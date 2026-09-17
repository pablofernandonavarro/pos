<?php

namespace Tests\Feature;

use App\Livewire\Pos\SesionVendedor;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Models\TurnoCaja;
use App\Services\CajaService;
use App\Services\SyncService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quién vendió cada venta puntual, distinto de quién abrió el turno: varias vendedoras
 * pueden usar la misma caja abierta a lo largo del día. El login (SesionVendedor) es
 * obligatorio y vive en el layout, no en una pantalla puntual, para que no se pueda
 * "olvidar" de identificarse y arrastrar el vendedor equivocado a otras ventas.
 */
class VendedorActivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();

        Cajero::create(['id' => 1, 'nombre' => 'Beto', 'rol' => 'cajero', 'pin_hash' => Hash::make('1111')]);
        Cajero::create(['id' => 2, 'nombre' => 'Ana', 'rol' => 'supervisor', 'pin_hash' => Hash::make('9999')]);

        app(CajaService::class)->abrirConPin(2, '9999', 5000);
    }

    public function test_login_con_pin_correcto_guarda_en_configuracion(): void
    {
        Livewire::test(SesionVendedor::class)
            ->set('vendedorSelectId', '1')
            ->set('pin', '1111')
            ->call('login')
            ->assertSet('error', null)
            ->assertSee('Beto')
            ->assertDontSee('¿Quién sos?');

        $this->assertSame('1', Configuracion::get('vendedor_activo_id'));
        $this->assertSame('Beto', Configuracion::get('vendedor_activo_nombre'));
    }

    public function test_login_con_pin_incorrecto_no_cambia_nada(): void
    {
        Configuracion::set('vendedor_activo_id', '2');
        Configuracion::set('vendedor_activo_nombre', 'Ana');

        Livewire::test(SesionVendedor::class)
            ->set('vendedorSelectId', '1')
            ->set('pin', '0000')
            ->call('login')
            ->assertSet('error', 'PIN incorrecto.');

        $this->assertSame('2', Configuracion::get('vendedor_activo_id'));
        $this->assertSame('Ana', Configuracion::get('vendedor_activo_nombre'));
    }

    public function test_logout_limpia_la_configuracion(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');

        Livewire::test(SesionVendedor::class)
            ->call('logout')
            ->assertSee('¿Quién sos?');

        $this->assertNull(Configuracion::get('vendedor_activo_id'));
        $this->assertNull(Configuracion::get('vendedor_activo_nombre'));
    }

    public function test_sin_vendedor_activo_y_con_cajeros_cargados_exige_login(): void
    {
        Livewire::test(SesionVendedor::class)
            ->assertSee('¿Quién sos?');
    }

    public function test_sin_cajeros_cargados_no_exige_login(): void
    {
        Cajero::query()->delete();

        Livewire::test(SesionVendedor::class)
            ->assertDontSee('¿Quién sos?');
    }

    /**
     * Sin turno abierto ya hay otro bloqueo (la pantalla de Venta pide abrir caja): no
     * tiene sentido superponer este login encima con el mismo z-index.
     */
    public function test_sin_turno_abierto_no_exige_login(): void
    {
        $turno = TurnoCaja::whereNull('cerrado_at')->firstOrFail();
        app(CajaService::class)->cerrar($turno, 5000);

        Livewire::test(SesionVendedor::class)
            ->assertDontSee('¿Quién sos?');
    }

    public function test_tras_el_tiempo_de_inactividad_se_desloguea_solo(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');
        Configuracion::set('vendedor_ultima_actividad', now()->subMinutes(25)->toIso8601String());

        Livewire::test(SesionVendedor::class)->assertSee('¿Quién sos?');

        $this->assertNull(Configuracion::get('vendedor_activo_id'));
    }

    public function test_con_actividad_reciente_no_se_desloguea(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');
        Configuracion::set('vendedor_ultima_actividad', now()->subMinutes(5)->toIso8601String());

        Livewire::test(SesionVendedor::class)->assertDontSee('¿Quién sos?');

        $this->assertSame('1', Configuracion::get('vendedor_activo_id'));
    }

    public function test_cerrar_el_turno_desloguea_al_vendedor(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');

        $turno = TurnoCaja::whereNull('cerrado_at')->firstOrFail();
        app(CajaService::class)->cerrar($turno, 5000);

        $this->assertNull(Configuracion::get('vendedor_activo_id'));
        $this->assertNull(Configuracion::get('vendedor_activo_nombre'));
    }

    public function test_una_venta_hereda_el_vendedor_activo(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');

        $p = $this->producto(['precio' => 1000]);
        $venta = app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);

        $this->assertSame(1, $venta->vendedor_id);
        $this->assertSame('Beto', $venta->vendedor_nombre);
        $this->assertSame('Beto', $venta->nombreVendedor());
    }

    public function test_una_venta_sin_vendedor_activo_cae_al_cajero_del_turno(): void
    {
        $p = $this->producto(['precio' => 1000]);
        $venta = app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);

        $this->assertNull($venta->vendedor_id);
        $this->assertNull($venta->vendedor_nombre);
        $this->assertSame('Ana', $venta->nombreVendedor(), 'sin vendedor activo, cae al cajero que abrió el turno');
    }

    public function test_el_payload_de_sync_incluye_el_vendedor(): void
    {
        Configuracion::set('vendedor_activo_id', '1');
        Configuracion::set('vendedor_activo_nombre', 'Beto');

        $p = $this->producto(['precio' => 1000]);
        app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);

        Http::fake(['*/sync/ventas*' => Http::response(['resultados' => [['uuid' => 'x', 'estado' => 'creada']]])]);

        app(SyncService::class)->pushVentas();

        Http::assertSent(fn ($request) => ($request['ventas'][0]['vendedor_id'] ?? null) === 1
            && ($request['ventas'][0]['vendedor_nombre'] ?? null) === 'Beto');
    }
}
