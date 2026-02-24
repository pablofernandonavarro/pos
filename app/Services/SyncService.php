<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\DetalleVenta;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncService
{
    public function __construct(
        private readonly ManagerApiService $managerApi
    ) {
    }

    /**
     * Sincronización completa inicial (productos, precios, stock).
     */
    public function syncInicial(): array
    {
        $resultados = [
            'productos' => ['success' => false, 'cantidad' => 0],
            'precios' => ['success' => false, 'cantidad' => 0],
            'stock' => ['success' => false, 'cantidad' => 0],
        ];

        DB::beginTransaction();

        try {
            // 1. Sincronizar productos
            $productosResult = $this->syncProductos();
            $resultados['productos'] = $productosResult;

            if (! $productosResult['success']) {
                throw new \Exception($productosResult['error'] ?? 'Error sincronizando productos');
            }

            // 2. Sincronizar precios
            $preciosResult = $this->syncPrecios();
            $resultados['precios'] = $preciosResult;

            if (! $preciosResult['success']) {
                throw new \Exception($preciosResult['error'] ?? 'Error sincronizando precios');
            }

            // 3. Sincronizar stock
            $stockResult = $this->syncStock();
            $resultados['stock'] = $stockResult;

            if (! $stockResult['success']) {
                throw new \Exception($stockResult['error'] ?? 'Error sincronizando stock');
            }

            DB::commit();

            return [
                'success' => true,
                'resultados' => $resultados,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en sincronización inicial', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'resultados' => $resultados,
            ];
        }
    }

    /**
     * Sincroniza productos desde el manager.
     */
    public function syncProductos(): array
    {
        $ultimaSync = Configuracion::get('ultima_sincronizacion_productos');

        $response = $this->managerApi->syncProductos($ultimaSync);

        if (! $response['success']) {
            return $response;
        }

        $productos = $response['data'];
        $sincronizados = 0;

        foreach ($productos as $productoData) {
            Producto::updateOrCreate(
                ['id' => $productoData['id']],
                [
                    'nombre' => $productoData['nombre'],
                    'codigo_interno' => $productoData['codigo_interno'] ?? null,
                    'codigo_barras' => $productoData['codigo_barras'] ?? null,
                    'busqueda' => $productoData['busqueda'] ?? $productoData['nombre'],
                    'precio' => $productoData['precio'] ?? 0,
                    'costo' => $productoData['costo'] ?? 0,
                    'stock' => 0, // El stock real vendrá de syncStock
                    'stock_critico' => $productoData['stock_critico'] ?? 0,
                    'imagen_url' => $productoData['imagen_url'] ?? null,
                    'descripcion_web' => $productoData['descripcion_web'] ?? null,
                    'marca' => $productoData['marca'] ?? null,
                    'color' => $productoData['color'] ?? null,
                    'n_talle' => $productoData['n_talle'] ?? null,
                    'genero' => $productoData['genero'] ?? null,
                    'n_grupo' => $productoData['n_grupo'] ?? null,
                    'n_subgrupo' => $productoData['n_subgrupo'] ?? null,
                    'n_temporada' => $productoData['n_temporada'] ?? null,
                    'product_type' => $productoData['product_type'] ?? 'simple',
                    'parent_id' => $productoData['parent_id'] ?? null,
                    'es_vendible' => $productoData['es_vendible'] ?? true,
                    'activo' => true,
                    'sincronizado_at' => now(),
                ]
            );

            $sincronizados++;
        }

        Configuracion::set('ultima_sincronizacion_productos', now()->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
        ];
    }

    /**
     * Sincroniza precios y listas desde el manager.
     */
    public function syncPrecios(): array
    {
        $response = $this->managerApi->syncPrecios();

        if (! $response['success']) {
            return $response;
        }

        $listas = $response['listas'];
        $precios = $response['precios'];

        // Sincronizar listas de precios
        foreach ($listas as $listaData) {
            ListaPrecio::updateOrCreate(
                ['id' => $listaData['id']],
                [
                    'nombre' => $listaData['nombre'],
                    'factor' => $listaData['factor'],
                    'es_default' => $listaData['es_default'],
                    'sincronizado_at' => now(),
                ]
            );
        }

        // Limpiar precios anteriores
        Precio::truncate();

        // Sincronizar precios específicos
        $sincronizados = 0;
        foreach ($precios as $precioData) {
            Precio::create([
                'lista_precio_id' => $precioData['lista_precio_id'],
                'product_id' => $precioData['product_id'],
                'precio_override' => $precioData['precio_override'],
                'vigencia_desde' => $precioData['vigencia_desde'],
                'vigencia_hasta' => $precioData['vigencia_hasta'],
                'sincronizado_at' => now(),
            ]);

            $sincronizados++;
        }

        Configuracion::set('ultima_sincronizacion_precios', now()->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
            'listas' => count($listas),
        ];
    }

    /**
     * Sincroniza stock desde el manager.
     */
    public function syncStock(): array
    {
        $response = $this->managerApi->syncStock();

        if (! $response['success']) {
            return $response;
        }

        $stockData = $response['data'];
        $sincronizados = 0;

        foreach ($stockData as $stock) {
            $producto = Producto::find($stock['product_id']);

            if ($producto) {
                $producto->update([
                    'stock' => $stock['cantidad'],
                ]);

                $sincronizados++;
            }
        }

        Configuracion::set('ultima_sincronizacion_stock', now()->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
        ];
    }

    /**
     * Envía ventas pendientes al manager.
     */
    public function pushVentas(): array
    {
        $ventas = Venta::with('detalles')->pendientes()->get();

        if ($ventas->isEmpty()) {
            return [
                'success' => true,
                'cantidad' => 0,
                'message' => 'No hay ventas pendientes de sincronizar',
            ];
        }

        $ventasData = [];

        foreach ($ventas as $venta) {
            $ventasData[] = [
                'lista_precio_id' => $venta->lista_precio_id,
                'numero_venta' => $venta->numero_venta,
                'fecha' => $venta->fecha->toIso8601String(),
                'subtotal' => $venta->subtotal,
                'descuento' => $venta->descuento,
                'total' => $venta->total,
                'items' => $venta->detalles->map(fn ($detalle) => [
                    'product_id' => $detalle->product_id,
                    'cantidad' => $detalle->cantidad,
                    'precio_unitario' => $detalle->precio_unitario,
                    'subtotal' => $detalle->subtotal,
                ])->toArray(),
            ];
        }

        $response = $this->managerApi->pushVentas($ventasData);

        if ($response['success']) {
            // Marcar ventas como sincronizadas
            foreach ($ventas as $venta) {
                $venta->marcarSincronizada();
            }

            return [
                'success' => true,
                'cantidad' => $ventas->count(),
                'message' => $response['message'],
            ];
        }

        return $response;
    }

    /**
     * Envía movimientos de stock pendientes al manager.
     */
    public function pushMovimientos(): array
    {
        $movimientos = MovimientoStock::pendientes()->get();

        if ($movimientos->isEmpty()) {
            return [
                'success' => true,
                'cantidad' => 0,
                'message' => 'No hay movimientos pendientes de sincronizar',
            ];
        }

        $movimientosData = $movimientos->map(fn ($mov) => [
            'product_id' => $mov->product_id,
            'tipo' => $mov->tipo,
            'cantidad' => $mov->cantidad,
            'referencia' => $mov->referencia,
            'fecha' => $mov->fecha->toIso8601String(),
        ])->toArray();

        $response = $this->managerApi->pushMovimientos($movimientosData);

        if ($response['success']) {
            // Marcar movimientos como sincronizados
            foreach ($movimientos as $movimiento) {
                $movimiento->marcarSincronizado();
            }

            return [
                'success' => true,
                'cantidad' => $movimientos->count(),
                'message' => $response['message'],
            ];
        }

        return $response;
    }

    /**
     * Sincronización bidireccional: pull + push.
     */
    public function syncBidireccional(): array
    {
        $resultados = [
            'pull' => ['success' => false],
            'push_ventas' => ['success' => false],
            'push_movimientos' => ['success' => false],
        ];

        // Pull: traer datos del manager
        $resultados['pull'] = $this->syncInicial();

        // Push: enviar ventas pendientes
        $resultados['push_ventas'] = $this->pushVentas();

        // Push: enviar movimientos pendientes
        $resultados['push_movimientos'] = $this->pushMovimientos();

        $success = $resultados['pull']['success']
            && $resultados['push_ventas']['success']
            && $resultados['push_movimientos']['success'];

        return [
            'success' => $success,
            'resultados' => $resultados,
        ];
    }
}
