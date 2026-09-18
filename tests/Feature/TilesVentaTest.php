<?php

namespace Tests\Feature;

use App\Livewire\Pos\Venta as PantallaVenta;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\TilesVentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accesos rápidos de la pantalla de venta (categorías con más productos y lo más
 * vendido), generados solos desde el catálogo y el historial de ventas.
 */
class TilesVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_categorias_ordena_por_cantidad_de_productos_e_ignora_sin_grupo(): void
    {
        $this->producto(['n_grupo' => 'Calzado']);
        $this->producto(['n_grupo' => 'Calzado']);
        $this->producto(['n_grupo' => 'Calzado']);
        $this->producto(['n_grupo' => 'Accesorios']);
        $this->producto(['n_grupo' => null]);
        $this->producto(['n_grupo' => '', 'stock' => 5]);
        $this->producto(['n_grupo' => 'Sin stock', 'es_vendible' => false]);

        $categorias = app(TilesVentaService::class)->categorias();

        $this->assertSame(['Calzado', 'Accesorios'], $categorias->pluck('grupo')->all());
        $this->assertSame(3, $categorias->firstWhere('grupo', 'Calzado')->total);
    }

    public function test_productos_de_categoria_solo_trae_lo_vendible_de_ese_grupo(): void
    {
        $p1 = $this->producto(['n_grupo' => 'Calzado', 'nombre' => 'Botas']);
        $this->producto(['n_grupo' => 'Calzado', 'es_vendible' => false, 'nombre' => 'Descontinuado']);
        $this->producto(['n_grupo' => 'Accesorios', 'nombre' => 'Cinturón']);

        $productos = app(TilesVentaService::class)->productosDeCategoria('Calzado');

        $this->assertCount(1, $productos);
        $this->assertSame($p1->id, $productos[0]['id']);
        $this->assertSame('Botas', $productos[0]['nombre']);
    }

    public function test_mas_vendidos_ordena_por_unidades_vendidas_en_los_ultimos_30_dias(): void
    {
        $popular = $this->producto(['nombre' => 'Popular']);
        $ocasional = $this->producto(['nombre' => 'Ocasional']);
        $viejo = $this->producto(['nombre' => 'Vendido hace meses']);

        $venta = Venta::create(['numero_venta' => 'V1', 'fecha' => now(), 'subtotal' => 1, 'total' => 1]);
        $venta->detalles()->create(['product_id' => $popular->id, 'cantidad' => 10, 'precio_unitario' => 1, 'subtotal' => 10]);
        $venta->detalles()->create(['product_id' => $ocasional->id, 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1]);

        $ventaVieja = Venta::create(['numero_venta' => 'V2', 'fecha' => now()->subDays(60), 'subtotal' => 1, 'total' => 1]);
        $ventaVieja->detalles()->create(['product_id' => $viejo->id, 'cantidad' => 100, 'precio_unitario' => 1, 'subtotal' => 100]);

        $masVendidos = app(TilesVentaService::class)->masVendidos();

        $this->assertSame([$popular->id, $ocasional->id], array_column($masVendidos, 'id'));
    }

    public function test_la_pantalla_de_venta_muestra_tiles_solo_sin_busqueda_activa(): void
    {
        $this->producto(['n_grupo' => 'Calzado', 'nombre' => 'Botas']);
        app(CajaService::class)->abrir('Ana', 0);

        Livewire::test(PantallaVenta::class)
            ->assertSee('Calzado')
            ->set('busqueda', 'algo')
            ->assertDontSee('Categorías');
    }

    public function test_tocar_una_categoria_muestra_sus_productos_y_se_puede_volver(): void
    {
        $p = $this->producto(['n_grupo' => 'Calzado', 'nombre' => 'Botas', 'stock' => 5]);
        app(CajaService::class)->abrir('Ana', 0);

        Livewire::test(PantallaVenta::class)
            ->call('verCategoria', 'Calzado')
            ->assertSet('categoriaSeleccionada', 'Calzado')
            ->assertSee('Botas')
            ->call('cerrarCategoria')
            ->assertSet('categoriaSeleccionada', null);
    }

    public function test_tocar_un_tile_de_producto_lo_agrega_directo_al_carrito(): void
    {
        $p = $this->producto(['n_grupo' => 'Calzado', 'nombre' => 'Botas', 'stock' => 5, 'precio' => 1000]);
        $venta = Venta::create(['numero_venta' => 'V1', 'fecha' => now(), 'subtotal' => 1, 'total' => 1]);
        $venta->detalles()->create(['product_id' => $p->id, 'cantidad' => 3, 'precio_unitario' => 1000, 'subtotal' => 3000]);
        app(CajaService::class)->abrir('Ana', 0);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->assertSet('carrito.0.product_id', $p->id);
    }
}
