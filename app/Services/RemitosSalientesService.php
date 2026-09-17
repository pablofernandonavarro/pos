<?php

namespace App\Services;

use App\Models\RemitoSaliente;
use Illuminate\Support\Carbon;

/**
 * Remitos que esta sucursal mandó a otras: crearlos y el historial con su estado actual
 * (en camino / confirmado / cancelado), que vive en el Manager.
 */
class RemitosSalientesService
{
    public function __construct(private ManagerApiService $api) {}

    public function configuracion(): array
    {
        return $this->api->obtenerConfiguracionRemitos();
    }

    public function sucursalesDestino(): array
    {
        $result = $this->api->obtenerSucursales();

        if (! $result['success']) {
            return [];
        }

        return $result['data'] ?? [];
    }

    /**
     * Crea el remito en el Manager (fuente de verdad del stock) y, si sale bien, sincroniza
     * el historial local para traerlo con su id y estado reales.
     *
     * @param  array<int, int>  $items  product_id => cantidad
     */
    public function crear(int $destinoSucursalId, array $items, ?string $observaciones = null): array
    {
        $resultado = $this->api->crearRemito($destinoSucursalId, $items, $observaciones);

        if ($resultado['success']) {
            $this->sincronizar();
        }

        return $resultado;
    }

    /**
     * Reemplaza la copia local por lo que informa el Manager (hasta los últimos 200). No
     * borra lo que ya no viene: a diferencia de remitos entrantes, acá no hay "esto ya no
     * importa", el historial completo es justamente el punto de esta pantalla.
     *
     * @return array{success: bool, cantidad?: int, error?: string}
     */
    public function sincronizar(): array
    {
        $respuesta = $this->api->obtenerRemitosEnviados();

        if (! $respuesta['success']) {
            return $respuesta;
        }

        foreach ($respuesta['data'] as $r) {
            RemitoSaliente::updateOrCreate(['id' => $r['id']], [
                'numero' => $r['numero'],
                'destino_sucursal_id' => $r['destino_sucursal_id'],
                'destino_nombre' => $r['destino'],
                'estado' => $r['estado'],
                'items' => $r['items'],
                'total_unidades' => collect($r['items'])->sum('cantidad'),
                'observaciones' => $r['observaciones'] ?? null,
                // A UTC antes de guardar: el cast datetime descarta el offset del ISO.
                'enviado_at' => Carbon::parse($r['remitido_at'])->utc(),
                'confirmado_at' => isset($r['confirmado_at']) ? Carbon::parse($r['confirmado_at'])->utc() : null,
            ]);
        }

        return ['success' => true, 'cantidad' => count($respuesta['data'])];
    }
}
