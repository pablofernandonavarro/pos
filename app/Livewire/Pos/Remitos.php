<?php

namespace App\Livewire\Pos;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\RemitoEntrante;
use App\Models\Sucursal;
use App\Services\RemitosEntrantesService;
use Livewire\Component;

/**
 * Mercadería en camino a esta sucursal y su recepción.
 */
class Remitos extends Component
{
    public ?string $mensaje = null;

    public ?string $error = null;

    public ?int $remitoRecibiendo = null;

    /** @var array<int, int> product_id => cantidad_recibida */
    public array $cantidadesRecibidas = [];

    public ?int $destinoRechazadosElegido = null;

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

    public function abrirModalRecepcion(int $remitoId): void
    {
        $remito = RemitoEntrante::find($remitoId);

        if (! $remito) {
            return;
        }

        $this->remitoRecibiendo = $remitoId;
        $this->cantidadesRecibidas = [];
        $this->destinoRechazadosElegido = null;

        foreach ($remito->items as $item) {
            $this->cantidadesRecibidas[$item['product_id']] = $item['cantidad'];
        }

        $this->mensaje = null;
        $this->error = null;
    }

    public function cerrarModal(): void
    {
        $this->remitoRecibiendo = null;
        $this->cantidadesRecibidas = [];
        $this->destinoRechazadosElegido = null;
    }

    public function confirmarRecepcionParcial(RemitosEntrantesService $remitos): void
    {
        if (! $this->remitoRecibiendo) {
            return;
        }

        $this->mensaje = null;
        $this->error = null;

        // Pasar cantidades y destino solo si están configurados
        $resultado = $remitos->recibir(
            $this->remitoRecibiendo,
            $this->cantidadesRecibidas,
            $this->destinoRechazadosElegido
        );

        if ($resultado['success']) {
            $this->mensaje = $resultado['mensaje'];
            $this->cerrarModal();
        } else {
            $this->error = $resultado['error'];
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

        $remitoEnProceso = $this->remitoRecibiendo ? RemitoEntrante::find($this->remitoRecibiendo) : null;
        $config = $this->obtenerConfiguracionRemitos();
        $sucursales = Sucursal::where('id', '!=', auth()->user()->sucursal_id)->get();

        return view('livewire.pos.remitos', [
            'remitos' => $remitos,
            'productos' => Producto::whereIn('id', $ids)->get()->keyBy('id'),
            'remitoEnProceso' => $remitoEnProceso,
            'config' => $config,
            'sucursales' => $sucursales,
        ])->layout('layouts.pos');
    }

    private function obtenerConfiguracionRemitos(): array
    {
        return [
            'destino_rechazados' => Configuracion::get('destino_rechazados', 'origen'),
            'ruta_directa' => Configuracion::get('ruta_directa', true),
        ];
    }
}
