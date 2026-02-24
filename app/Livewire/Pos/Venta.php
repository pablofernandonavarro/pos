<?php

namespace App\Livewire\Pos;

use App\Models\DetalleVenta;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Venta as VentaModel;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Venta extends Component
{
    public string $busqueda = '';
    public array $carrito = [];
    public ?int $listaId = null;
    public float $subtotal = 0;
    public float $descuento = 0;
    public float $total = 0;
    public string $clienteNombre = '';
    public string $clienteDocumento = '';
    public string $metodoPago = 'efectivo';
    public bool $modalBusqueda = false;
    public array $resultadosBusqueda = [];

    public function mount(): void
    {
        $listaDefault = ListaPrecio::getDefault();
        $this->listaId = $listaDefault?->id;
    }

    public function updatedBusqueda(): void
    {
        if (strlen($this->busqueda) >= 2) {
            $this->buscarProductos();
        } else {
            $this->resultadosBusqueda = [];
        }
    }

    public function buscarProductos(): void
    {
        $this->resultadosBusqueda = Producto::vendible()
            ->search($this->busqueda)
            ->limit(10)
            ->get()
            ->map(function ($producto) {
                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'codigo' => $producto->codigo_interno ?? $producto->codigo_barras,
                    'precio' => $producto->getPrecioEfectivo($this->listaId),
                    'stock' => $producto->stock,
                    'imagen' => $producto->imagen_url,
                ];
            })
            ->toArray();

        if (count($this->resultadosBusqueda) === 1) {
            $this->agregarAlCarrito($this->resultadosBusqueda[0]['id']);
            $this->busqueda = '';
            $this->resultadosBusqueda = [];
        } elseif (count($this->resultadosBusqueda) > 1) {
            $this->modalBusqueda = true;
        }
    }

    public function agregarAlCarrito(int $productoId): void
    {
        $producto = Producto::find($productoId);

        if (! $producto || ! $producto->es_vendible) {
            $this->dispatch('error', message: 'Producto no disponible');

            return;
        }

        if ($producto->stock <= 0) {
            $this->dispatch('error', message: 'Producto sin stock');

            return;
        }

        // Verificar si ya está en el carrito
        $key = array_search($productoId, array_column($this->carrito, 'product_id'));

        if ($key !== false) {
            // Incrementar cantidad
            if ($this->carrito[$key]['cantidad'] < $producto->stock) {
                $this->carrito[$key]['cantidad']++;
                $this->carrito[$key]['subtotal'] = $this->carrito[$key]['cantidad'] * $this->carrito[$key]['precio_unitario'];
            } else {
                $this->dispatch('error', message: 'Stock insuficiente');

                return;
            }
        } else {
            // Agregar nuevo item
            $this->carrito[] = [
                'product_id' => $producto->id,
                'nombre' => $producto->nombre,
                'codigo' => $producto->codigo_interno ?? $producto->codigo_barras,
                'cantidad' => 1,
                'precio_unitario' => $producto->getPrecioEfectivo($this->listaId),
                'subtotal' => $producto->getPrecioEfectivo($this->listaId),
                'stock_disponible' => $producto->stock,
            ];
        }

        $this->calcularTotales();
        $this->busqueda = '';
        $this->modalBusqueda = false;
        $this->resultadosBusqueda = [];
    }

    public function incrementarCantidad(int $index): void
    {
        if ($this->carrito[$index]['cantidad'] < $this->carrito[$index]['stock_disponible']) {
            $this->carrito[$index]['cantidad']++;
            $this->carrito[$index]['subtotal'] = $this->carrito[$index]['cantidad'] * $this->carrito[$index]['precio_unitario'];
            $this->calcularTotales();
        } else {
            $this->dispatch('error', message: 'Stock insuficiente');
        }
    }

    public function decrementarCantidad(int $index): void
    {
        if ($this->carrito[$index]['cantidad'] > 1) {
            $this->carrito[$index]['cantidad']--;
            $this->carrito[$index]['subtotal'] = $this->carrito[$index]['cantidad'] * $this->carrito[$index]['precio_unitario'];
            $this->calcularTotales();
        }
    }

    public function eliminarItem(int $index): void
    {
        unset($this->carrito[$index]);
        $this->carrito = array_values($this->carrito); // Reindexar
        $this->calcularTotales();
    }

    public function calcularTotales(): void
    {
        $this->subtotal = array_sum(array_column($this->carrito, 'subtotal'));
        $this->total = $this->subtotal - $this->descuento;
    }

    public function finalizarVenta(): void
    {
        if (empty($this->carrito)) {
            $this->dispatch('error', message: 'El carrito está vacío');

            return;
        }

        try {
            DB::transaction(function () {
                // Crear venta
                $venta = VentaModel::create([
                    'lista_precio_id' => $this->listaId,
                    'numero_venta' => VentaModel::generarNumeroVenta(),
                    'fecha' => now(),
                    'subtotal' => $this->subtotal,
                    'descuento' => $this->descuento,
                    'total' => $this->total,
                    'cliente_nombre' => $this->clienteNombre ?: null,
                    'cliente_documento' => $this->clienteDocumento ?: null,
                    'metodo_pago' => $this->metodoPago,
                ]);

                // Crear detalles y actualizar stock
                foreach ($this->carrito as $item) {
                    DetalleVenta::create([
                        'venta_id' => $venta->id,
                        'product_id' => $item['product_id'],
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $item['precio_unitario'],
                        'subtotal' => $item['subtotal'],
                    ]);

                    // Registrar movimiento de stock
                    MovimientoStock::registrar(
                        $item['product_id'],
                        'venta',
                        -$item['cantidad'],
                        $venta->numero_venta
                    );
                }

                $this->dispatch('venta-finalizada', ventaId: $venta->id, numeroVenta: $venta->numero_venta);
                $this->resetearVenta();
            });
        } catch (\Exception $e) {
            $this->dispatch('error', message: 'Error al procesar la venta: '.$e->getMessage());
        }
    }

    public function resetearVenta(): void
    {
        $this->carrito = [];
        $this->subtotal = 0;
        $this->descuento = 0;
        $this->total = 0;
        $this->clienteNombre = '';
        $this->clienteDocumento = '';
        $this->metodoPago = 'efectivo';
        $this->busqueda = '';
    }

    public function render()
    {
        return view('livewire.pos.venta')->layout('layouts.pos');
    }
}
