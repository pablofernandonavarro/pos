<?php

namespace App\Livewire\Configuracion;

use App\Models\Configuracion;
use App\Services\ManagerApiService;
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

    public function mount(): void
    {
        // Verificar si ya está configurado
        if (Configuracion::isConfigured()) {
            $this->configurado = true;
            $this->managerApiUrl = Configuracion::get('manager_api_url', '');
            $this->puntoDeVentaId = (int) Configuracion::get('punto_de_venta_id', 0);
        }
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
