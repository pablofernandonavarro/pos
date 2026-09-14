<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\SyncService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncStockYCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
    }

    public function test_el_stock_del_manager_suma_lo_vendido_offline_que_todavia_no_llego(): void
    {
        $p = $this->producto(['id' => 115, 'precio' => 100, 'stock' => 5]);
        app(CajaService::class)->abrir('Ana', 0);
        app(VentaService::class)->registrar([['product_id' => 115, 'cantidad' => 2]], [['medio' => 'efectivo', 'monto' => 200]]);
        $this->assertSame(3, $p->fresh()->stock);

        // Vuelve la red y el pull de stock llega ANTES que el push: el Manager todavía dice 5
        Http::fake(['*/sync/stock' => Http::response(['data' => [['product_id' => 115, 'cantidad' => 5]]])]);
        app(SyncService::class)->syncStock();

        $this->assertSame(3, $p->fresh()->stock, 'no puede "devolver" lo vendido offline');
    }

    public function test_sin_conexion_el_pull_de_stock_no_toca_nada_ni_marca_contacto(): void
    {
        $p = $this->producto(['stock' => 7]);
        $antes = Configuracion::get('ultima_sincronizacion_stock');
        $this->travel(10)->minutes();

        Http::fake(fn () => throw new ConnectionException('sin red'));

        $this->assertFalse(app(SyncService::class)->syncStock()['success']);
        $this->assertSame(7, $p->fresh()->stock);
        $this->assertSame($antes, Configuracion::get('ultima_sincronizacion_stock'));
    }

    public function test_con_el_catalogo_sin_verificar_no_se_envian_ventas(): void
    {
        Configuracion::set('ultima_sincronizacion_productos', null);
        Venta::create(['numero_venta' => 'V1', 'fecha' => now(), 'subtotal' => 1, 'total' => 1]);

        $r = app(SyncService::class)->pushVentas();

        $this->assertStringContainsString('retenido', $r['message']);
        Http::assertNothingSent();
    }

    public function test_el_catalogo_completo_realinea_ids_corridos_y_mueve_las_ventas(): void
    {
        Configuracion::set('ultima_sincronizacion_productos', null);

        // Caja vieja: SQLite numeró seguido y los ids quedaron corridos respecto del Manager
        $zapLocal = $this->producto(['id' => 108, 'nombre' => 'Zapatillas', 'codigo_interno' => 'ZAP001']);
        $artLocal = $this->producto(['id' => 105, 'nombre' => 'Remera', 'codigo_interno' => 'ART-5626']);
        $venta = Venta::create(['numero_venta' => 'V1', 'fecha' => now(), 'subtotal' => 1, 'total' => 1]);
        $venta->detalles()->create(['product_id' => 108, 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1]);

        $catalogo = [
            ['id' => 115, 'nombre' => 'Zapatillas', 'codigo_interno' => 'ZAP001', 'precio' => 1000, 'es_vendible' => true],
            ['id' => 110, 'nombre' => 'Remera', 'codigo_interno' => 'ART-5626', 'precio' => 500, 'es_vendible' => true],
        ];

        Http::fake([
            '*/sync/productos*' => Http::response(['data' => $catalogo]),
            '*/sync/stock' => Http::response(['data' => [['product_id' => 115, 'cantidad' => 6], ['product_id' => 110, 'cantidad' => 20]]]),
        ]);

        $this->assertTrue(app(SyncService::class)->syncStock()['success']);

        $this->assertSame([110, 115], Producto::orderBy('id')->pluck('id')->all());
        $this->assertSame('ZAP001', Producto::find(115)->codigo_interno);
        $this->assertSame(115, $venta->detalles()->first()->product_id);
        $this->assertSame(6, Producto::find(115)->stock);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame([115], Producto::search('ZAP001')->pluck('id')->all(), 'el índice FTS sigue al id nuevo');
        $this->assertNotNull(Configuracion::get('ultima_sincronizacion_productos'));
    }

    public function test_un_producto_nuevo_se_crea_con_el_id_del_manager(): void
    {
        Http::fake(['*/sync/productos*' => Http::response(['data' => [
            ['id' => 999, 'nombre' => 'Nuevo', 'codigo_interno' => 'NEW1', 'precio' => 10, 'es_vendible' => true],
        ]])]);

        app(SyncService::class)->syncProductos();

        $this->assertSame('Nuevo', Producto::find(999)?->nombre);
    }

    public function test_las_llamadas_al_manager_informan_version_y_tipo_de_instalacion(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        app(SyncService::class)->syncPromociones();

        // El mismo test corre en la instalación clásica y en la copia de escritorio.
        Http::assertSent(fn (Request $r) => $r->header('X-POS-Version')[0] === \App\Support\VersionPos::actual()
            && $r->header('X-POS-Tipo')[0] === \App\Support\VersionPos::tipo());
    }
}
