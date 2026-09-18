<?php

namespace Tests\Feature;

use App\Exceptions\CajaException;
use App\Livewire\Pos\Ajustes;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Livewire\Pos\Ventas;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\MovimientoStock;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\AutorizacionService;
use App\Services\CajaService;
use App\Services\DevolucionService;
use App\Services\SyncService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class Etapa2Test extends TestCase
{
    use RefreshDatabase;

    private Cajero $cajera;

    private Cajero $supervisora;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        $this->cajera = Cajero::create(['id' => 1, 'nombre' => 'Beto', 'rol' => 'cajero', 'pin_hash' => Hash::make('1111')]);
        $this->supervisora = Cajero::create(['id' => 2, 'nombre' => 'Ana', 'rol' => 'supervisor', 'pin_hash' => Hash::make('9999')]);
    }

    // ---- PIN y apertura ----------------------------------------------------------

    public function test_con_cajeros_la_caja_se_abre_con_pin_y_no_con_nombre_libre(): void
    {
        $caja = app(CajaService::class);

        try {
            $caja->abrir('Cualquiera', 0);
            $this->fail('Con cajeros cargados no se abre con nombre libre');
        } catch (CajaException) {
        }

        try {
            $caja->abrirConPin(1, '0000', 0);
            $this->fail('PIN incorrecto');
        } catch (CajaException $e) {
            $this->assertSame('PIN incorrecto.', $e->getMessage());
        }

        $turno = $caja->abrirConPin(1, '1111', 500);
        $this->assertSame('Beto', $turno->cajero);
        $this->assertSame(1, $turno->cajero_id);
    }

    public function test_el_pin_se_bloquea_despues_de_varios_intentos(): void
    {
        RateLimiter::clear('pin-cajero:1');
        $autorizacion = app(AutorizacionService::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $autorizacion->verificar(1, '0000');
            } catch (CajaException) {
            }
        }

        try {
            $autorizacion->verificar(1, '1111');
            $this->fail('Bloqueado aunque ahora el PIN sea correcto');
        } catch (CajaException $e) {
            $this->assertStringContainsString('Demasiados intentos', $e->getMessage());
        }
    }

    public function test_un_cajero_no_autoriza_lo_que_requiere_supervisor(): void
    {
        $this->expectExceptionMessage('no es supervisor');

        app(AutorizacionService::class)->verificar(1, '1111', requiereSupervisor: true);
    }

    public function test_pantalla_de_apertura_con_pin(): void
    {
        Livewire::test(PantallaVenta::class)
            ->assertSee('Beto')
            ->set('aperturaCajeroId', '1')->set('aperturaPin', '1234')->call('abrirCaja')
            ->assertSet('error', 'PIN incorrecto.')
            ->assertSet('aperturaPin', '')
            ->set('aperturaCajeroId', '1')->set('aperturaPin', '1111')->set('aperturaFondo', '100')->call('abrirCaja')
            ->assertSet('error', null);

        $this->assertSame('Beto', TurnoCaja::sole()->cajero);
    }

    // ---- Descuento manual --------------------------------------------------------

    public function test_descuento_dentro_del_limite_sin_supervisor_y_arriba_con_supervisor(): void
    {
        Configuracion::set('descuento_maximo_sin_autorizacion', '10');
        app(CajaService::class)->abrirConPin(1, '1111', 0);
        $p = $this->producto(['precio' => 1000, 'stock' => 10]);
        $ventas = app(VentaService::class);

        $v1 = $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 900]], null, [], 100);
        $this->assertSame('100.00', $v1->descuento_manual);
        $this->assertNull($v1->descuento_autorizado_por);
        $this->assertSame('900.00', $v1->total);

        try {
            $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 800]], null, [], 200);
            $this->fail('20% supera el límite de 10%');
        } catch (CajaException $e) {
            $this->assertStringContainsString('necesita autorización', $e->getMessage());
        }

        try {
            $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 800]], null, [], 200, $this->cajera);
            $this->fail('Un cajero no autoriza');
        } catch (CajaException) {
        }

        $v2 = $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 800]], null, [], 200, $this->supervisora);
        $this->assertSame('Ana', $v2->descuento_autorizado_por);
        $this->assertSame('800.00', $v2->total);

        // El descuento no puede cubrir todo el total
        $this->expectException(CajaException::class);
        $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1]], null, [], 1000, $this->supervisora);
    }

    public function test_descuento_manual_desde_la_pantalla_con_pin_de_supervisor(): void
    {
        Configuracion::set('descuento_maximo_sin_autorizacion', '5');
        app(CajaService::class)->abrirConPin(1, '1111', 0);
        $p = $this->producto(['precio' => 2000]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->call('abrirCobro')
            ->set('descuentoTipo', 'porcentaje')->set('descuentoValor', '25')
            ->call('aplicarDescuento')
            ->assertSet('error', 'Elegí el supervisor que autoriza.')
            ->assertSet('descuentoManualCentavos', 0)
            ->set('descuentoValor', '25')->set('descuentoSupervisorId', '2')->set('descuentoPin', '9999')
            ->call('aplicarDescuento')
            ->assertSet('error', null)
            ->assertSet('descuentoManualCentavos', 50000)
            ->assertSet('pagoMonto', '1500')
            ->call('finalizarVenta')
            ->assertSet('error', null);

        $venta = Venta::sole();
        $this->assertSame('500.00', $venta->descuento_manual);
        $this->assertSame('Ana', $venta->descuento_autorizado_por);
        $this->assertSame('1500.00', $venta->total);
    }

    // ---- Devoluciones ------------------------------------------------------------

    private function ventaConDescuento(): Venta
    {
        app(CajaService::class)->abrirConPin(1, '1111', 10000);
        $zap = $this->producto(['id' => 115, 'nombre' => 'Zapatillas', 'precio' => 3000, 'stock' => 10]);
        $rem = $this->producto(['id' => 116, 'nombre' => 'Remera', 'precio' => 1000, 'stock' => 10]);

        // Subtotal 7000 (2 zap + 1 rem), 10% de descuento autorizado → cobrado 6300
        return app(VentaService::class)->registrar(
            [['product_id' => 115, 'cantidad' => 2], ['product_id' => 116, 'cantidad' => 1]],
            [['medio' => 'efectivo', 'monto' => 6300]],
            null, [], 700, $this->supervisora
        );
    }

    public function test_devolucion_parcial_prorratea_el_descuento_y_devuelve_stock(): void
    {
        $venta = $this->ventaConDescuento();
        $lineaZap = $venta->detalles->firstWhere('product_id', 115);

        $dev = app(DevolucionService::class)->registrar($venta, [$lineaZap->id => 1], 'Talle', 'efectivo', $this->supervisora);

        $this->assertSame('parcial', $dev->tipo);
        $this->assertSame('2700.00', $dev->total, '3000 con el 10% de la venta');
        $this->assertSame(9, \App\Models\Producto::find(115)->stock, '10 − 2 vendidas + 1 devuelta');
        $this->assertSame(1, MovimientoStock::where('tipo', 'devolucion')->count());
        $this->assertSame(1, $lineaZap->fresh()->cantidadDevolvible());

        $resumen = app(CajaService::class)->resumen(app(CajaService::class)->turnoAbierto());
        $this->assertSame(2700.0, $resumen['devoluciones']['efectivo']);
        $this->assertSame(3600.0, $resumen['ventas']['neto']);
        // 10000 fondo + 6300 venta − 2700 devuelto
        $this->assertSame(13600.0, $resumen['efectivo']['esperado']);
    }

    public function test_anulacion_devuelve_exacto_lo_cobrado_aunque_haya_redondeos(): void
    {
        app(CajaService::class)->abrirConPin(1, '1111', 1000);
        $p = $this->producto(['precio' => 100, 'stock' => 10]);
        // 3 unidades de $100 con descuento de $1: cada una vale 99,666...
        $venta = app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 3]], [['medio' => 'efectivo', 'monto' => 299]], null, [], 1, $this->supervisora);
        $linea = $venta->detalles->sole();
        $servicio = app(DevolucionService::class);

        $d1 = $servicio->registrar($venta, [$linea->id => 1], 'x', 'efectivo', $this->supervisora);
        $d2 = $servicio->registrar($venta->fresh(), [$linea->id => 1], 'x', 'efectivo', $this->supervisora);
        $d3 = $servicio->registrar($venta->fresh(), [$linea->id => 1], 'x', 'efectivo', $this->supervisora);

        $this->assertSame(29900, collect([$d1, $d2, $d3])->sum(fn ($d) => (int) round($d->total * 100)), 'la suma cierra exacto con lo cobrado');
        $this->assertSame('parcial', $d3->tipo, 'no es anulación: ya había devoluciones');
    }

    public function test_validaciones_de_devolucion(): void
    {
        $venta = $this->ventaConDescuento();
        $linea = $venta->detalles->first();
        $servicio = app(DevolucionService::class);

        $casos = [
            'más de lo vendido' => fn () => $servicio->registrar($venta, [$linea->id => 99], 'x', 'efectivo', $this->supervisora),
            'sin motivo' => fn () => $servicio->registrar($venta, [$linea->id => 1], '  ', 'efectivo', $this->supervisora),
            'cajero no autoriza' => fn () => $servicio->registrar($venta, [$linea->id => 1], 'x', 'efectivo', $this->cajera),
            'línea de otra venta' => fn () => $servicio->registrar($venta, [99999 => 1], 'x', 'efectivo', $this->supervisora),
            'reintegro inventado' => fn () => $servicio->registrar($venta, [$linea->id => 1], 'x', 'bitcoin', $this->supervisora),
            'nada que devolver' => fn () => $servicio->registrar($venta, [$linea->id => 0], 'x', 'efectivo', $this->supervisora),
        ];

        foreach ($casos as $caso => $accion) {
            try {
                $accion();
                $this->fail("Tenía que rechazar: {$caso}");
            } catch (CajaException) {
            }
        }

        $this->assertSame(0, Devolucion::count());
    }

    public function test_no_se_devuelve_en_efectivo_mas_de_lo_que_hay_en_la_caja(): void
    {
        $venta = $this->ventaConDescuento();
        app(CajaService::class)->registrarMovimiento(app(CajaService::class)->turnoAbierto(), 'retiro', 16000, 'Depósito');

        $this->expectExceptionMessage('No hay efectivo suficiente');
        app(DevolucionService::class)->registrar($venta, [$venta->detalles->first()->id => 1], 'x', 'efectivo', $this->supervisora);
    }

    public function test_pantalla_de_ventas_anula_con_supervisor(): void
    {
        $venta = $this->ventaConDescuento();

        Livewire::test(Ventas::class)
            ->assertSee($venta->numero_venta)
            ->call('verDetalle', $venta->id)
            ->call('iniciarDevolucion')
            ->call('devolverTodo')
            ->set('motivo', 'Cliente se arrepintió')
            ->set('supervisorId', '1')->set('supervisorPin', '1111')
            ->call('registrarDevolucion')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'no es supervisor'))
            ->set('supervisorId', '2')->set('supervisorPin', '9999')
            ->call('registrarDevolucion')
            ->assertSet('error', null)
            ->assertSet('mensaje', fn ($m) => str_contains((string) $m, 'Venta anulada'));

        $dev = Devolucion::sole();
        $this->assertSame('anulacion', $dev->tipo);
        $this->assertSame('6300.00', $dev->total);
        $this->get(route('pos.devolucion.comprobante', $dev))->assertOk()->assertSee('ANULACIÓN')->assertSee('Cliente se arrepintió');
    }

    // ---- Ajustes y sync ----------------------------------------------------------

    public function test_ajustes_con_cajeros_requiere_supervisor(): void
    {
        Livewire::test(Ajustes::class)
            ->set('limiteDescuento', '50')
            ->call('guardar')
            ->assertSet('error', 'Elegí el supervisor que autoriza.');
        $this->assertNotSame('50', Configuracion::get('descuento_maximo_sin_autorizacion'));

        Livewire::test(Ajustes::class)
            ->set('limiteDescuento', '15')->set('supervisorId', '2')->set('supervisorPin', '9999')
            ->call('guardar')->assertSet('error', null);
        $this->assertSame(15.0, VentaService::limiteDescuentoSinAutorizacion());
    }

    public function test_sync_de_cajeros_reemplaza_la_lista(): void
    {
        Http::fake(['*/sync/cajeros' => Http::response(['data' => [
            ['id' => 2, 'nombre' => 'Ana María', 'rol' => 'supervisor', 'pin_hash' => Hash::make('5555'), 'foto_url' => 'https://manager.test/storage/usuarios/fotos/ana.jpg'],
            // Un Manager anterior a las fotos no manda el campo.
            ['id' => 7, 'nombre' => 'Nuevo', 'rol' => 'cajero', 'pin_hash' => Hash::make('7777')],
        ]])]);

        app(SyncService::class)->syncCajeros();

        $this->assertEqualsCanonicalizing([2, 7], Cajero::pluck('id')->all());
        $this->assertSame('Ana María', Cajero::find(2)->nombre);
        $this->assertSame('https://manager.test/storage/usuarios/fotos/ana.jpg', Cajero::find(2)->foto_url);
        $this->assertNull(Cajero::find(7)->foto_url);
        app(AutorizacionService::class)->verificar(2, '5555', requiereSupervisor: true);
    }

    public function test_la_devolucion_se_envia_despues_de_su_venta_y_con_el_descuento(): void
    {
        $venta = $this->ventaConDescuento();
        app(DevolucionService::class)->registrar($venta, [$venta->detalles->first()->id => 1], 'Falla', 'medio_original', $this->supervisora);

        Http::fake(function (Request $r) {
            $clave = str_ends_with($r->url(), '/sync/ventas') ? 'ventas' : 'devoluciones';

            return Http::response(['message' => 'ok', 'resultados' => collect($r[$clave])->map(fn ($x) => ['uuid' => $x['uuid'], 'status' => 'creada'])->all()]);
        });

        $sync = app(SyncService::class);

        $this->assertSame(0, $sync->pushDevoluciones()['cantidad'], 'la venta todavía no llegó al Manager');
        $sync->pushVentas();
        $this->assertSame(1, $sync->pushDevoluciones()['cantidad']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sync/ventas')
            && (float) $r['ventas'][0]['descuento_manual'] === 700.0 && $r['ventas'][0]['descuento_autorizado_por'] === 'Ana');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sync/devoluciones')
            && $r['devoluciones'][0]['venta_uuid'] === $venta->uuid && $r['devoluciones'][0]['reintegro'] === 'medio_original');
    }
}
