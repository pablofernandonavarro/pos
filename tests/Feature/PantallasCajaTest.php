<?php

namespace Tests\Feature;

use App\Jobs\SincronizarPendientes;
use App\Livewire\Pos\Caja;
use App\Livewire\Pos\EstadoSync;
use App\Livewire\Pos\Stock as PantallaStock;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Models\MovimientoStock;
use App\Models\PromocionBancaria;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PantallasCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
    }

    public function test_sin_caja_abierta_la_venta_pide_abrirla(): void
    {
        Livewire::test(PantallaVenta::class)
            ->assertSee('La caja está cerrada')
            ->set('aperturaCajero', '')
            ->call('abrirCaja')
            ->assertSet('error', 'Indicá quién abre la caja.')
            ->set('aperturaCajero', 'Ana')
            ->set('aperturaFondo', '3000')
            ->call('abrirCaja')
            ->assertSet('error', null)
            ->assertSee('turno #1')
            ->assertDontSee('La caja está cerrada');

        $this->assertSame('3000.00', TurnoCaja::sole()->fondo_inicial);
    }

    public function test_venta_completa_con_pago_dividido_y_promocion(): void
    {
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 5000, 'stock' => 4]);
        PromocionBancaria::create(['id' => 5, 'nombre' => 'Galicia 20%', 'banco' => 'Galicia', 'medios' => ['credito'], 'porcentaje' => 20]);

        $pantalla = Livewire::test(PantallaVenta::class)
            ->call('confirmarBusqueda', $p->codigo_interno)
            ->call('incrementarCantidad', 0)
            ->assertSet('total', 10000.0)
            ->call('abrirCobro')
            ->assertSet('cobrando', true)
            ->assertSet('pagoMonto', '10000')
            // 4000 en efectivo, recibe 5000
            ->set('pagoMonto', '4000')
            ->set('pagoRecibido', '5000')
            ->call('agregarPago')
            ->assertSet('error', null)
            ->assertSet('pagoMonto', '6000')
            // el resto con crédito Galicia: la promo aparece y se elige
            ->call('elegirMedio', 'credito')
            ->set('pagoTarjeta', 'visa')
            ->set('pagoBanco', 'Galicia')
            ->set('pagoCuotas', '3')
            ->assertSee('Galicia 20%')
            ->set('pagoPromocionId', 5)
            ->call('finalizarVenta')
            ->assertSet('error', null)
            ->assertSet('cobrando', false)
            ->assertSet('carrito', []);

        $this->assertStringContainsString('Vuelto $1.000,00', $pantalla->get('exito'));

        $venta = Venta::with('pagos')->sole();
        $this->assertSame('8800.00', $venta->total, '10000 − 20% de 6000');
        $this->assertSame(2, $p->fresh()->stock);
        Queue::assertPushed(SincronizarPendientes::class);
    }

    public function test_no_se_finaliza_si_falta_cobrar_ni_se_agrega_un_pago_de_mas(): void
    {
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 1000]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->call('abrirCobro')
            ->set('pagoMonto', '1500')
            ->call('agregarPago')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'supera lo que falta'))
            ->assertSet('pagos', [])
            ->set('pagoMonto', '')
            ->call('finalizarVenta')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'Falta cobrar'))
            ->assertSet('cobrando', true);

        $this->assertSame(0, Venta::count());
    }

    public function test_el_navegador_no_puede_alterar_carrito_total_pagos_ni_autorizaciones(): void
    {
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 1000]);

        $pantalla = Livewire::test(PantallaVenta::class)->call('agregarAlCarrito', $p->id)->call('abrirCobro');

        foreach ([
            'carrito.0.precio_unitario' => 1,
            'total' => 1,
            'pagos' => [['entrada' => ['medio' => 'efectivo', 'monto' => 1], 'calculo' => ['monto' => 100000]]],
            'descuentoManualCentavos' => 99000,
            'descuentoAutorizadoPorId' => 1,
        ] as $propiedad => $valor) {
            try {
                $pantalla->set($propiedad, $valor);
                $this->fail("{$propiedad} no debería poder modificarse desde el navegador");
            } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            }
        }

        $this->assertSame(0, Venta::count());
    }

    public function test_pantalla_de_caja_movimientos_cierre_e_informes(): void
    {
        $turno = app(CajaService::class)->abrir('Ana', 1000);

        Livewire::test(Caja::class)
            ->assertSee('Turno #1')
            ->set('movimientoTipo', 'retiro')
            ->set('movimientoMonto', '5000')
            ->set('movimientoMotivo', 'Depósito')
            ->call('registrarMovimiento')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'No hay tanto efectivo'))
            ->set('movimientoMonto', '400')
            ->call('registrarMovimiento')
            ->assertSet('error', null)
            ->call('iniciarCierre')
            ->call('cerrarCaja')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'Contá el efectivo'))
            ->set('efectivoContado', '550')
            ->assertSee('Faltan $50,00')
            ->call('cerrarCaja')
            ->assertSet('cerradoId', $turno->id)
            ->assertSee('Cierre Z #1 realizado. Faltante de $50,00.');

        $this->get(route('pos.caja.informe', $turno))
            ->assertOk()
            ->assertSee('INFORME Z #1')
            ->assertSee('FALTANTE')
            ->assertSee('Depósito');
    }

    public function test_el_informe_x_de_un_turno_abierto_se_calcula_en_el_momento(): void
    {
        $turno = app(CajaService::class)->abrir('Ana', 1234.5);

        $this->get(route('pos.caja.informe', $turno))
            ->assertOk()
            ->assertSee('INFORME X #1')
            ->assertSee('$1.234,50')
            ->assertDontSee('FALTANTE');
    }

    public function test_el_header_cuenta_cierres_pendientes_de_enviar(): void
    {
        $caja = app(CajaService::class);
        $caja->abrir('Ana', 0);

        $this->assertSame(0, Livewire::test(EstadoSync::class)->get('pendientes'), 'el turno abierto no cuenta');

        $caja->cerrar($caja->turnoAbierto(), 0);

        $this->assertSame(1, Livewire::test(EstadoSync::class)->get('pendientes'));
    }

    public function test_las_pantallas_nuevas_cargan(): void
    {
        $this->get(route('pos.caja'))->assertOk()->assertSee('No hay caja abierta');
        $this->get(route('pos.venta'))->assertOk()->assertSee('La caja está cerrada');
    }

    public function test_los_movimientos_de_stock_se_muestran_y_se_cuentan_en_hora_argentina(): void
    {
        $this->travelTo(Carbon::parse('2026-09-19 12:00:00', 'America/Argentina/Buenos_Aires')->utc());
        $producto = $this->producto();

        MovimientoStock::create([
            'product_id' => $producto->id,
            'tipo' => 'venta',
            'cantidad' => -1,
            'referencia' => 'PDV01-000008',
            'fecha' => '2026-09-19 02:00:00',
        ]);
        MovimientoStock::create([
            'product_id' => $producto->id,
            'tipo' => 'venta',
            'cantidad' => -2,
            'referencia' => 'PDV01-000009',
            'fecha' => '2026-09-19 13:00:00',
        ]);

        Livewire::test(PantallaStock::class)
            ->assertViewHas('stats', fn (array $stats): bool => $stats['total_hoy'] === 1)
            ->assertSee('18/09/2026 23:00')
            ->assertSee('19/09/2026 10:00')
            ->assertDontSee('19/09/2026 02:00')
            ->assertDontSee('19/09/2026 13:00');
    }
}
