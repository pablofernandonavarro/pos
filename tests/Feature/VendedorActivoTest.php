<?php

namespace Tests\Feature;

use App\Livewire\Pos\Venta;
use App\Models\Cajero;
use App\Models\Configuracion;
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
 * pueden usar la misma caja abierta a lo largo del día.
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

    public function test_fijar_vendedor_con_pin_correcto_guarda_en_configuracion(): void
    {
        Livewire::test(Venta::class)
            ->set('vendedorSelectId', '1')
            ->set('vendedorPin', '1111')
            ->call('fijarVendedor')
            ->assertSet('error', null)
            ->assertSee('Beto');

        $this->assertSame('1', Configuracion::get('vendedor_activo_id'));
        $this->assertSame('Beto', Configuracion::get('vendedor_activo_nombre'));
    }

    public function test_fijar_vendedor_con_pin_incorrecto_no_cambia_nada(): void
    {
        Configuracion::set('vendedor_activo_id', '2');
        Configuracion::set('vendedor_activo_nombre', 'Ana');

        Livewire::test(Venta::class)
            ->set('vendedorSelectId', '1')
            ->set('vendedorPin', '0000')
            ->call('fijarVendedor')
            ->assertSet('error', 'PIN incorrecto.');

        $this->assertSame('2', Configuracion::get('vendedor_activo_id'));
        $this->assertSame('Ana', Configuracion::get('vendedor_activo_nombre'));
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
