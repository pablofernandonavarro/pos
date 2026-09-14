<?php

namespace Tests\Feature;

use App\Livewire\Pos\Venta as PantallaVenta;
use App\Livewire\Pos\Ventas as PantallaVentas;
use App\Models\DetalleVenta;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\SyncService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VariantesIndumentariaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Producto> SKU => variante */
    private array $remera = [];

    private Producto $pantalon;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        app(CajaService::class)->abrir('Ana', 0);

        // CONF-4301 como llega del Manager: solo las variantes, con el modelo.
        $stock = ['NEG-S' => 4, 'NEG-M' => 10, 'NEG-L' => 8, 'BLA-S' => 3, 'BLA-M' => 0, 'BLA-L' => 6];
        $ean = 7791234101001;

        foreach ($stock as $sufijo => $cantidad) {
            [$c, $t] = explode('-', $sufijo);
            $sku = "CONF-4301-{$sufijo}";
            $color = $c === 'NEG' ? 'Negro' : 'Blanco';

            $this->remera[$sku] = $this->producto([
                'nombre' => "Remera básica - {$color} - {$t}", 'codigo_interno' => $sku, 'codigo_barras' => (string) $ean++,
                'precio' => 12900, 'stock' => $cantidad, 'color' => $color, 'n_talle' => $t,
                'parent_id' => 57, 'modelo_codigo' => 'CONF-4301', 'modelo_nombre' => 'Remera básica', 'product_type' => 'simple',
            ]);
        }

        $this->pantalon = $this->producto(['nombre' => 'Pantalón básico', 'codigo_interno' => 'PANT-001', 'codigo_barras' => '7791234200011', 'stock' => 10]);
    }

    private function vender(Producto $producto, int $cantidad): Venta
    {
        return app(VentaService::class)->registrar(
            [['product_id' => $producto->id, 'cantidad' => $cantidad]],
            [['medio' => 'efectivo', 'monto' => (float) $producto->getPrecioEfectivo() * $cantidad]]
        );
    }

    // ---- Test mínimo obligatorio ----------------------------------------------------------

    public function test_vender_una_variante_descuenta_solo_esa_variante(): void
    {
        $negroM = $this->remera['CONF-4301-NEG-M'];
        $negroL = $this->remera['CONF-4301-NEG-L'];

        $this->vender($negroM, 2);
        $this->assertSame([8, 8], [$negroM->fresh()->stock, $negroL->fresh()->stock]);

        $this->vender($negroL, 1);
        $this->assertSame([8, 7], [$negroM->fresh()->stock, $negroL->fresh()->stock]);

        $otras = collect($this->remera)->except(['CONF-4301-NEG-M', 'CONF-4301-NEG-L']);
        $this->assertSame([4, 3, 0, 6], $otras->map(fn ($p) => $p->fresh()->stock)->values()->all(), 'ninguna otra variante cambia');

        // La venta queda con la variante: producto, color, talle y SKU.
        $linea = DetalleVenta::with('producto')->where('product_id', $negroM->id)->sole();
        $this->assertSame(['CONF-4301', 'Negro', 'M', 'CONF-4301-NEG-M', 2], [$linea->producto->modelo_codigo, $linea->producto->color, $linea->producto->n_talle, $linea->producto->codigo_interno, $linea->cantidad]);
        $this->assertSame($negroM->id, app(SyncService::class)->payloadVenta($linea->venta)['items'][0]['product_id'], 'al Manager viaja el id de la variante');
    }

    // ---- Validación del POS ---------------------------------------------------------------

    public function test_caso_a_y_b_buscar_el_modelo_pide_color_y_talle_y_agrega_esa_variante(): void
    {
        Livewire::test(PantallaVenta::class)
            ->call('confirmarBusqueda', 'CONF-4301')
            ->assertSet('carrito', [])
            ->assertSet('selectorModelo', 'CONF-4301')
            ->assertSee('Remera básica')->assertSee('Negro')->assertSee('Blanco')
            ->call('agregarVarianteSeleccionada')
            ->assertSet('error', 'Elegí color y talle.')
            ->set('selectorColor', 'Negro')->set('selectorTalle', 'M')
            ->assertSee('SKU CONF-4301-NEG-M')
            ->call('agregarVarianteSeleccionada')
            ->assertSet('error', null)
            ->assertSet('selectorModelo', null)
            ->assertSet('carrito.0.product_id', $this->remera['CONF-4301-NEG-M']->id)
            ->assertSet('carrito.0.variante', 'Negro / M')
            ->assertSet('carrito.0.modelo_codigo', 'CONF-4301')
            ->assertSee('SKU CONF-4301-NEG-M');
    }

    public function test_caso_c_escanear_el_barcode_de_la_variante_la_agrega_directo(): void
    {
        $negroM = $this->remera['CONF-4301-NEG-M'];

        Livewire::test(PantallaVenta::class)
            ->call('confirmarBusqueda', $negroM->codigo_barras)
            ->assertSet('selectorModelo', null)
            ->assertSet('carrito.0.product_id', $negroM->id)
            ->assertSet('carrito.0.variante', 'Negro / M')
            // El SKU exacto también va directo.
            ->call('confirmarBusqueda', 'conf-4301-neg-m')
            ->assertSet('carrito.0.cantidad', 2);
    }

    public function test_caso_d_el_producto_simple_sigue_igual(): void
    {
        Livewire::test(PantallaVenta::class)
            ->call('confirmarBusqueda', 'PANT-001')
            ->assertSet('selectorModelo', null)
            ->assertSet('carrito.0.product_id', $this->pantalon->id)
            ->assertSet('carrito.0.variante', null)
            ->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', null);

        $this->assertSame(9, $this->pantalon->fresh()->stock);
    }

    public function test_combinacion_sin_stock_no_se_agrega_y_la_venta_completa_funciona(): void
    {
        Livewire::test(PantallaVenta::class)
            ->call('abrirSelectorVariantes', 'CONF-4301')
            ->set('selectorColor', 'Blanco')->set('selectorTalle', 'M')
            ->assertSee('sin stock')
            ->call('agregarVarianteSeleccionada')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'Sin stock'))
            ->assertSet('carrito', [])
            ->set('selectorColor', 'Negro')->set('selectorTalle', 'L')
            ->call('agregarVarianteSeleccionada')
            ->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', null);

        $this->assertSame(7, $this->remera['CONF-4301-NEG-L']->fresh()->stock);
        $this->assertSame(10, $this->remera['CONF-4301-NEG-M']->fresh()->stock);

        Livewire::test(PantallaVentas::class)
            ->call('verDetalle', Venta::sole()->id)
            ->assertSee('Negro / L')->assertSee('SKU CONF-4301-NEG-L');
    }

    public function test_busqueda_por_codigo_de_modelo_devuelve_todas_sus_variantes(): void
    {
        $this->assertSame(6, Producto::vendible()->search('CONF-4301')->count());
        $this->assertSame(1, Producto::vendible()->search('CONF-4301-NEG-M')->count());
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', '38', '40'], collect(['40', 'L', 'XS', '38', 'M', 'XL', 'S'])->sortBy(fn ($t) => Producto::ordenTalle($t))->values()->all());
    }

    public function test_el_sync_guarda_el_modelo_de_cada_variante(): void
    {
        Http::fake(['manager.fake/api/v1/sync/productos*' => Http::response(['data' => [
            ['id' => 500, 'nombre' => 'Buzo - Gris - L', 'codigo_interno' => 'CONF-4302-GRI-L', 'codigo_barras' => '7791234102012', 'color' => 'Gris', 'n_talle' => 'L',
                'product_type' => 'simple', 'parent_id' => 90, 'parent_codigo_interno' => 'CONF-4302', 'parent_nombre' => 'Buzo canguro frisa', 'precio' => 34900, 'es_vendible' => true],
            ['id' => 501, 'nombre' => 'Gorra', 'codigo_interno' => 'ACC-002', 'product_type' => 'simple', 'parent_id' => null, 'parent_codigo_interno' => null, 'precio' => 9900, 'es_vendible' => true],
        ]])]);

        app(SyncService::class)->syncProductos();

        $buzo = Producto::find(500);
        $this->assertSame(['CONF-4302', 'Buzo canguro frisa', 'Gris / L'], [$buzo->modelo_codigo, $buzo->modelo_nombre, $buzo->descripcionVariante()]);
        $this->assertNull(Producto::find(501)->descripcionVariante());
    }
}
