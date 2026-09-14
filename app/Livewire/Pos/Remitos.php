<?php

namespace App\Livewire\Pos;

use App\Models\Producto;
use App\Models\RemitoEntrante;
use App\Services\RemitosEntrantesService;
use Livewire\Component;

/**
 * Mercadería en camino a esta sucursal y su recepción.
 */
class Remitos extends Component
{
    public ?string $mensaje = null;

    public ?string $error = null;

    public function actualizar(RemitosEntrantesService $remitos): void
    {
        $this->mensaje = null;
        $this->error = null;

        $resultado = $remitos->sincronizar();

        if (! $resultado['success']) {
            $this->error = 'No se pudo consultar el Manager: '.($resultado['error'] ?? 'error desconocido').'. Se muestra lo último que se bajó.';
        }

        $this->dispatch('remitos-actualizados');
    }

    public function recibir(int $remitoId, RemitosEntrantesService $remitos): void
    {
        $this->mensaje = null;
        $this->error = null;

        $resultado = $remitos->recibir($remitoId);

        if ($resultado['success']) {
            $this->mensaje = $resultado['mensaje'];
        } else {
            $this->error = $resultado['error'];
        }

        $this->dispatch('remitos-actualizados');
    }

    public function render()
    {
        $remitos = RemitoEntrante::orderBy('remitido_at')->get();

        $ids = $remitos->flatMap(fn (RemitoEntrante $r) => array_column($r->items, 'product_id'))->unique();

        return view('livewire.pos.remitos', [
            'remitos' => $remitos,
            'productos' => Producto::whereIn('id', $ids)->get()->keyBy('id'),
        ])->layout('layouts.pos');
    }
}
