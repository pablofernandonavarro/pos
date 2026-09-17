<?php

namespace App\Livewire\Pos;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Services\RemitosSalientesService;
use Livewire\Component;

/**
 * Crear remito: seleccionar destino, productos y cantidades.
 */
class RemitoNuevo extends Component
{
    public int $destinoSucursalId = 0;

    /** @var array<int, int> product_id => cantidad */
    public array $items = [];

    public string $observaciones = '';

    public ?string $busqueda = null;

    public ?string $mensaje = null;

    public ?string $error = null;

    private ?bool $rutaDirecta = null;

    public function mount(RemitosSalientesService $remitos): void
    {
        $config = $remitos->configuracion();

        if ($config['success']) {
            $this->rutaDirecta = (bool) ($config['data']['ruta_directa'] ?? true);
        }
    }

    /**
     * $cantidad llega como string: viene del value de un <input> HTML vía
     * $event.currentTarget.previousElementSibling.value, no de wire:model.
     */
    public function agregarProducto(int $productoId, string $cantidad): void
    {
        $cantidad = (int) $cantidad;

        if ($cantidad > 0) {
            $this->items[$productoId] = ($this->items[$productoId] ?? 0) + $cantidad;
        }

        $this->busqueda = null;
    }

    public function quitarProducto(int $productoId): void
    {
        unset($this->items[$productoId]);
    }

    public function crear(RemitosSalientesService $remitos): void
    {
        $this->mensaje = null;
        $this->error = null;

        if (! $this->destinoSucursalId) {
            $this->error = 'Selecciona una sucursal destino.';

            return;
        }

        if (empty($this->items)) {
            $this->error = 'Agrega al menos un producto.';

            return;
        }

        $resultado = $remitos->crear(
            $this->destinoSucursalId,
            $this->items,
            $this->observaciones ?: null
        );

        if ($resultado['success']) {
            $sucursal = Sucursal::find($this->destinoSucursalId)?->nombre ?? 'destino';

            session()->flash('success', "Remito #{$resultado['data']['numero']} creado hacia {$sucursal}");
            $this->redirect(route('pos.remitos'));
        } else {
            $this->error = $resultado['error'] ?? 'Error desconocido';
        }
    }

    public function render()
    {
        // No hay usuario autenticado por Laravel en esta caja (login por PIN de cajero, sin
        // tabla users): la sucursal propia es la que guardó ManagerApiService::authenticate().
        $sucursales = Sucursal::where('id', '!=', (int) Configuracion::get('sucursal_id'))->get();

        // Si ruta_directa es false, filtrar solo la Central
        if ($this->rutaDirecta === false) {
            $sucursales = $sucursales->where('is_central', true);
        }

        $productosItems = array_keys($this->items);
        $productos = Producto::where('es_vendible', true)
            ->when($this->busqueda, fn ($q) => $q->where('busqueda', 'like', "%{$this->busqueda}%"))
            ->take(10)
            ->get();

        return view('livewire.pos.remito-nuevo', [
            'sucursales' => $sucursales,
            'productos' => $productos,
            'productosAñadidos' => Producto::whereIn('id', $productosItems)->get()->keyBy('id'),
            'rutaDirecta' => $this->rutaDirecta,
        ])->layout('layouts.pos');
    }
}
