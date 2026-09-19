<?php

namespace App\Livewire\Pos;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\SyncService;
use Livewire\Component;
use Livewire\WithPagination;

class Stock extends Component
{
    use WithPagination;

    public string $busqueda = '';
    public string $tipo = 'entrada';
    public int $cantidad = 1;
    public string $referencia = '';
    public ?int $productoSeleccionado = null;
    public bool $showModal = false;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function seleccionarProducto(int $productoId): void
    {
        $producto = Producto::find($productoId);

        if (!$producto) {
            return;
        }

        $this->productoSeleccionado = $productoId;
        $this->showModal = true;
        $this->tipo = 'entrada';
        $this->cantidad = 1;
        $this->referencia = '';
    }

    public function cerrarModal(): void
    {
        $this->showModal = false;
        $this->productoSeleccionado = null;
        $this->cantidad = 1;
        $this->referencia = '';
        $this->tipo = 'entrada';
        $this->resetValidation();
    }

    public function registrarMovimiento(): void
    {
        $this->validate([
            'tipo' => 'required|in:entrada,salida,ajuste',
            'cantidad' => 'required|integer|min:1',
            'referencia' => 'nullable|string|max:100',
        ]);

        $producto = Producto::find($this->productoSeleccionado);

        if (!$producto) {
            $this->dispatch('error', 'Producto no encontrado');
            return;
        }

        // Calcular la cantidad final según el tipo
        $cantidadFinal = match($this->tipo) {
            'entrada' => $this->cantidad,
            'salida' => -abs($this->cantidad),
            'ajuste' => $this->cantidad - $producto->stock,
        };

        // Crear movimiento
        MovimientoStock::create([
            'product_id' => $producto->id,
            'tipo' => $this->tipo,
            'cantidad' => $cantidadFinal,
            'referencia' => $this->referencia ?: null,
            'fecha' => now(),
        ]);

        // Actualizar stock del producto
        if ($this->tipo === 'ajuste') {
            $producto->update(['stock' => $this->cantidad]);
        } else {
            $producto->increment('stock', $cantidadFinal);
        }

        $this->dispatch('movimiento-creado', "Movimiento registrado correctamente. Stock actual: {$producto->fresh()->stock}");
        $this->cerrarModal();
    }

    public function sincronizarMovimientos(): void
    {
        $syncService = app(SyncService::class);
        $result = $syncService->pushMovimientos();

        if ($result['success']) {
            if ($result['cantidad'] > 0) {
                $this->dispatch('movimiento-creado', "✓ Se sincronizaron {$result['cantidad']} movimiento(s) con el manager");
            } else {
                $this->dispatch('movimiento-creado', "No hay movimientos pendientes de sincronizar");
            }
        } else {
            \Log::error('Error sincronizando movimientos', ['error' => $result['error'] ?? 'Desconocido']);
            $this->dispatch('error', 'Error al sincronizar con el manager. Los movimientos quedan guardados localmente.');
        }
    }

    public function render()
    {
        $query = Producto::query();

        if ($this->busqueda) {
            $query->search($this->busqueda);
        }

        $productos = $query->orderBy('nombre')->paginate(15);

        $inicioDelDiaLocal = now()->timezone(config('pos.zona_horaria'))->startOfDay()->utc();

        $stats = [
            'pendientes' => MovimientoStock::pendientes()->count(),
            'total_hoy' => MovimientoStock::where('fecha', '>=', $inicioDelDiaLocal)->count(),
        ];

        $movimientosRecientes = MovimientoStock::with('producto')
            ->latest('fecha')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $productoModal = $this->productoSeleccionado
            ? Producto::find($this->productoSeleccionado)
            : null;

        return view('livewire.pos.stock', [
            'productos' => $productos,
            'stats' => $stats,
            'movimientosRecientes' => $movimientosRecientes,
            'productoModal' => $productoModal,
        ])->layout('layouts.pos');
    }
}
