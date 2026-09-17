<?php

namespace Tests\Feature;

use App\Livewire\Configuracion\Inicial;
use App\Livewire\Pos\EstadoSync;
use App\Livewire\Pos\Remitos;
use App\Models\Configuracion;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\RemitoEntrante;
use App\Services\RemitosEntrantesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RemitosEInstalacionTest extends TestCase
{
    use RefreshDatabase;

    private array $remito = [
        'id' => 7, 'numero' => '000007', 'origen' => 'Central', 'remitido_at' => '2026-09-13T18:30:00-03:00', 'observaciones' => 'Bulto 1',
        'items' => [['product_id' => 115, 'codigo' => 'ZAP001', 'nombre' => 'Zapatillas', 'cantidad' => 5]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ---- Remitos ----------------------------------------------------------------

    public function test_sincroniza_la_lista_y_la_alerta_la_cuenta(): void
    {
        $this->configurarCaja();
        Http::fake(['*/pos/remitos' => Http::response(['data' => [$this->remito]])]);

        $r = app(RemitosEntrantesService::class)->sincronizar();

        $this->assertCount(1, $r['nuevos']);
        $this->assertSame('2026-09-13 21:30:00', RemitoEntrante::find(7)->getRawOriginal('remitido_at'), 'se guarda en UTC');
        $this->assertSame(1, Livewire::test(EstadoSync::class)->get('remitosPorRecibir'));
        $this->assertCount(0, app(RemitosEntrantesService::class)->sincronizar()['nuevos'], 'ya no es nuevo');
    }

    public function test_recibir_aplica_el_stock_del_manager_sin_crear_movimientos_locales(): void
    {
        $this->configurarCaja();
        $p = $this->producto(['id' => 115, 'stock' => 1]);
        RemitoEntrante::create([...$this->remito, 'total_unidades' => 5]);

        Http::fake(['*/pos/remitos/7/recibir' => Http::response(['status' => 'recibido', 'stock' => [['product_id' => 115, 'cantidad' => 6]]])]);

        Livewire::test(Remitos::class)
            ->call('recibir', 7)
            ->assertSet('error', null)
            ->assertSet('mensaje', fn ($m) => str_contains((string) $m, '5 unidades'));

        $this->assertSame(6, $p->fresh()->stock);
        $this->assertSame(0, RemitoEntrante::count());
        $this->assertSame(0, MovimientoStock::count(), 'un movimiento local se enviaría y duplicaría el stock en el Manager');
    }

    public function test_remito_cancelado_se_saca_de_la_lista_y_sin_red_queda(): void
    {
        $this->configurarCaja();
        RemitoEntrante::create([...$this->remito, 'total_unidades' => 5]);
        RemitoEntrante::create([...$this->remito, 'id' => 8, 'numero' => '000008', 'total_unidades' => 5]);

        Http::fake([
            '*/pos/remitos/7/recibir' => Http::response(['message' => 'fue cancelado'], 409),
            '*/pos/remitos/8/recibir' => fn () => throw new ConnectionException('sin red'),
        ]);

        $servicio = app(RemitosEntrantesService::class);

        $this->assertFalse($servicio->recibir(7)['success']);
        $this->assertNull(RemitoEntrante::find(7));

        $sinRed = $servicio->recibir(8);
        $this->assertFalse($sinRed['success']);
        $this->assertStringContainsString('Sin conexión', $sinRed['error']);
        $this->assertNotNull(RemitoEntrante::find(8));
    }

    // ---- Instalación -----------------------------------------------------------

    public function test_una_caja_sin_instalar_va_a_la_pantalla_de_codigo(): void
    {
        $this->get('/')->assertRedirect(route('pos.configuracion'));
        $this->get('/caja')->assertRedirect(route('pos.configuracion'));
        $this->get(route('pos.configuracion'))->assertOk()->assertSee('Instalar esta caja');
    }

    public function test_instalar_con_codigo_contra_el_manager(): void
    {
        Http::fake([
            'manager.fake/api/v1/pos/provision' => Http::response(['punto_de_venta_id' => 4, 'secret' => 's3cr3t', 'pdv_nombre' => 'caja 2', 'sucursal_id' => 1, 'sucursal_nombre' => 'Villa Bosh']),
            'manager.fake/api/v1/pos/auth' => Http::response(['token' => 'tok', 'sucursal_id' => 1, 'sucursal_nombre' => 'Villa Bosh', 'pdv_nombre' => 'caja 2']),
            'manager.fake/api/v1/sync/productos*' => Http::response(['data' => [['id' => 115, 'nombre' => 'Zapatillas', 'codigo_interno' => 'ZAP001', 'precio' => 1000, 'es_vendible' => true]]]),
            'manager.fake/api/v1/sync/precios*' => Http::response(['listas' => [], 'precios' => []]),
            'manager.fake/api/v1/sync/stock*' => Http::response(['data' => [['product_id' => 115, 'cantidad' => 4]]]),
            'manager.fake/api/v1/sync/promociones' => Http::response(['data' => []]),
        ]);

        Livewire::test(Inicial::class)
            ->set('urlManager', 'http://manager.fake')   // sin /api/v1: se completa solo
            ->set('codigo', 'abcd-efgh')
            ->call('instalarConCodigo')
            ->assertSet('error', null)
            ->assertSet('configurado', true);

        $this->assertTrue(Configuracion::isConfigured());
        $this->assertSame('http://manager.fake/api/v1', Configuracion::get('manager_api_url'));
        $this->assertSame(4, Producto::find(115)->stock);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pos/provision') && $r['codigo'] === 'ABCD-EFGH');
    }

    public function test_codigo_invalido_muestra_el_error_del_manager(): void
    {
        Http::fake(['*/pos/provision' => Http::response(['message' => 'El código no existe.'], 422)]);

        Livewire::test(Inicial::class)
            ->set('urlManager', 'http://manager.fake')
            ->set('codigo', 'ZZZZ-ZZZZ')
            ->call('instalarConCodigo')
            ->assertSet('error', 'El código no existe.')
            ->assertSet('configurado', false);

        $this->assertFalse(Configuracion::isConfigured());
    }
}
