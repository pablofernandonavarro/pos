<?php

namespace App\Livewire\Pos;

use App\Models\Producto;
use Livewire\Component;
use Livewire\WithPagination;

class Productos extends Component
{
    use WithPagination;

    public string $busqueda = '';
    public string $ordenar = 'nombre';
    public bool $soloConStock = false;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Producto::query();

        if ($this->busqueda) {
            $query->search($this->busqueda);
        }

        if ($this->soloConStock) {
            $query->where('stock', '>', 0);
        }

        $productos = $query->orderBy($this->ordenar)
            ->paginate(20);

        $stats = [
            'total' => Producto::count(),
            'con_stock' => Producto::where('stock', '>', 0)->count(),
            'sin_stock' => Producto::where('stock', '<=', 0)->count(),
            'valor_total' => Producto::where('stock', '>', 0)->sum(\DB::raw('stock * precio')),
        ];

        return view('livewire.pos.productos', [
            'productos' => $productos,
            'stats' => $stats,
        ])->layout('layouts.pos');
    }
}
