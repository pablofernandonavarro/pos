<?php

namespace App\Livewire\Configuracion;

use App\Models\Configuracion;
use App\Services\EscritorioService;
use App\Services\ManagerApiService;
use App\Services\ProvisionService;
use App\Services\SyncService;
use Livewire\Component;

class Inicial extends Component
{
    public string $managerApiUrl = '';
    public int $puntoDeVentaId = 0;
    public string $secret = '';
    public bool $conectando = false;
    public bool $sincronizando = false;
    public ?string $error = null;
    public ?string $mensaje = null;
    public bool $configurado = false;

    /** 'codigo' (lo normal) o 'manual' (URL + id + secret, para soporte). */
    public string $modo = 'codigo';
    public string $urlManager = '';
    public string $codigo = '';

    /** @var array<int, string> */
    public array $preparado = [];

    public function mount(): void
    {
        $this->urlManager = Configuracion::get('manager_api_url') ?: config('pos.manager_api_url', '');

        // Verificar si ya está configurado
        if (Configuracion::isConfigured()) {
            $this->configurado = true;
            $this->managerApiUrl = Configuracion::get('manager_api_url', '');
            $this->puntoDeVentaId = (int) Configuracion::get('punto_de_venta_id', 0);
        }
    }

    /**
     * Alta con el código que genera el Manager. En la app de escritorio es el único
     * camino práctico: no hay consola donde correr pos:provision.
     */
    public function instalarConCodigo(ProvisionService $provision, EscritorioService $escritorio): void
    {
        $this->validate([
            'urlManager' => 'required|url',
            'codigo' => 'required|string|min:4',
        ], [
            'urlManager.required' => 'Poné la dirección del Manager',
            'urlManager.url' => 'La dirección no es válida (ej: http://manager.miempresa.com)',
            'codigo.required' => 'Pegá el código que te dio el Manager',
            'codigo.min' => 'El código es muy corto',
        ]);

        $this->error = null;
        $this->mensaje = null;

        $resultado = $provision->instalar($this->urlManager, $this->codigo);

        if (! $resultado['success']) {
            $this->error = $resultado['error'];

            return;
        }

        $this->codigo = '';
        $this->configurado = true;
        $this->preparado = $escritorio->prepararMaquina($resultado['pdv_nombre']);

        if (! $resultado['sync']['success']) {
            $this->error = 'La caja quedó instalada pero no se pudo bajar el catálogo: '
                .($resultado['sync']['error'] ?? 'error desconocido')
                .'. Probá con "Sincronizar Catálogo Inicial".';

            return;
        }

        $r = $resultado['sync']['resultados'];
        $this->mensaje = "✅ {$resultado['pdv_nombre']} lista para vender: "
            ."{$r['productos']['cantidad']} productos, {$r['stock']['cantidad']} registros de stock.";

        $this->dispatch('sync-completo');
    }

    /** Reinstalar: una caja ya configurada que necesita canjear un código nuevo. */
    public function usarOtroCodigo(): void
    {
        $this->configurado = false;
        $this->modo = 'codigo';
        $this->error = null;
        $this->mensaje = null;
    }

    public function conectar(): void
    {
        $this->validate([
            'managerApiUrl' => 'required|url',
            'puntoDeVentaId' => 'required|integer|min:1',
            'secret' => 'required|string|min:8',
        ], [
            'managerApiUrl.required' => 'La URL del servidor es obligatoria',
            'managerApiUrl.url' => 'La URL del servidor no es válida',
            'puntoDeVentaId.required' => 'El ID del punto de venta es obligatorio',
            'puntoDeVentaId.integer' => 'El ID del punto de venta debe ser un número',
            'puntoDeVentaId.min' => 'El ID del punto de venta debe ser mayor a 0',
            'secret.required' => 'La clave secreta es obligatoria',
            'secret.min' => 'La clave secreta debe tener al menos 8 caracteres',
        ]);

        $this->conectando = true;
        $this->error = null;
        $this->mensaje = null;

        try {
            // Guardar URL del manager
            Configuracion::set('manager_api_url', rtrim($this->managerApiUrl, '/'));
            Configuracion::set('punto_de_venta_secret', $this->secret);

            // Intentar autenticación
            $managerApi = new ManagerApiService();
            $resultado = $managerApi->authenticate($this->puntoDeVentaId, $this->secret);

            if ($resultado['success']) {
                $this->mensaje = '✅ Conexión exitosa. Iniciando sincronización...';
                $this->configurado = true;
                $this->preparado = app(EscritorioService::class)->prepararMaquina((string) Configuracion::get('pdv_nombre'));

                // Iniciar sincronización inicial
                $this->dispatch('configuracion-exitosa');
            } else {
                $this->error = $resultado['error'] ?? 'Error de autenticación';
            }
        } catch (\Exception $e) {
            $this->error = 'Error al conectar: '.$e->getMessage();
        } finally {
            $this->conectando = false;
        }
    }

    public function sincronizar(): void
    {
        $this->sincronizando = true;
        $this->error = null;
        $this->mensaje = null;

        try {
            $syncService = app(SyncService::class);
            $resultado = $syncService->syncInicial();

            if ($resultado['success']) {
                $this->mensaje = sprintf(
                    '✅ Sincronización completada: %d productos, %d precios, %d registros de stock',
                    $resultado['resultados']['productos']['cantidad'] ?? 0,
                    $resultado['resultados']['precios']['cantidad'] ?? 0,
                    $resultado['resultados']['stock']['cantidad'] ?? 0
                );

                // Redirigir al POS después de 2 segundos
                $this->dispatch('sync-completo');
            } else {
                $this->error = $resultado['error'] ?? 'Error en la sincronización';
            }
        } catch (\Exception $e) {
            $this->error = 'Error al sincronizar: '.$e->getMessage();
        } finally {
            $this->sincronizando = false;
        }
    }

    public function render()
    {
        return view('livewire.configuracion.inicial')->layout('layouts.guest');
    }
}
