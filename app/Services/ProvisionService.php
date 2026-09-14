<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Http;

/**
 * Alta de una caja a partir de un código de instalación del Manager.
 *
 * Lo usan `pos:provision` (instalación clásica, por consola) y la pantalla de
 * configuración inicial (app de escritorio, donde no hay consola donde tipear).
 */
class ProvisionService
{
    /**
     * Acepta tanto la URL de la API (http://manager/api/v1) como la del Manager a secas
     * (http://manager), que es lo que la gente copia de la barra del navegador.
     */
    public static function normalizarUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url !== '' && ! str_contains($url, '/api/')) {
            $url .= '/api/v1';
        }

        return $url;
    }

    /**
     * @return array{success: bool, error?: string, etapa?: string, pdv_nombre?: string, sucursal_nombre?: string, punto_de_venta_id?: int, sync?: array}
     */
    public function instalar(string $url, string $codigo): array
    {
        $url = self::normalizarUrl($url);
        $codigo = strtoupper(trim($codigo));

        try {
            $respuesta = Http::timeout(30)
                ->acceptJson()
                ->post("{$url}/pos/provision", ['codigo' => $codigo]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'etapa' => 'conexion',
                'error' => "No se pudo conectar con el Manager en {$url}. Verificá la dirección y que esta máquina tenga red.",
            ];
        }

        if (! $respuesta->successful()) {
            return [
                'success' => false,
                'etapa' => 'codigo',
                'error' => $respuesta->json('message', 'El Manager rechazó el código.'),
            ];
        }

        $datos = $respuesta->json();

        // La URL se guarda en la base porque es de ahí de donde lee ManagerApiService
        // en runtime, no del .env.
        Configuracion::set('manager_api_url', $url);

        // Los servicios se resuelven recién ahora: ManagerApiService lee url y token de
        // `configuracion` en su constructor, e inyectarlos antes daría credenciales viejas.
        $auth = app()->make(ManagerApiService::class)->authenticate(
            (int) $datos['punto_de_venta_id'],
            $datos['secret']
        );

        if (! $auth['success']) {
            return [
                'success' => false,
                'etapa' => 'autenticacion',
                'error' => 'El código se canjeó pero falló la autenticación: '.$auth['error'].'. Pedí un código nuevo en el Manager.',
            ];
        }

        // Otra instancia nueva, para que tome el token recién emitido.
        $sync = app()->make(SyncService::class)->syncInicial();

        return [
            'success' => true,
            'punto_de_venta_id' => (int) $datos['punto_de_venta_id'],
            'pdv_nombre' => $datos['pdv_nombre'],
            'sucursal_nombre' => $datos['sucursal_nombre'],
            'sync' => $sync,
        ];
    }
}
