<?php

namespace App\Livewire\Pos;

use App\Services\SyncService;
use Livewire\Component;

class Sincronizacion extends Component
{
    public bool $sincronizando = false;
    public bool $completado = false;
    public array $resultados = [];
    public ?string $error = null;

    public function mount()
    {
        // Iniciar sincronización automáticamente al cargar
        $this->sincronizar();
    }

    public function sincronizar()
    {
        $this->sincronizando = true;
        $this->error = null;
        $this->completado = false;

        try {
            $syncService = app(SyncService::class);
            $resultado = $syncService->syncBidireccional();

            $this->resultados = $resultado['resultados'];
            $this->completado = true;

            if (!$resultado['success']) {
                $this->error = 'Algunos elementos no se sincronizaron correctamente';
            }

            // Redirigir después de 2 segundos
            $this->dispatch('sync-completed');
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->sincronizando = false;
        }
    }

    public function render()
    {
        return view('livewire.pos.sincronizacion')->layout('layouts.pos');
    }
}
