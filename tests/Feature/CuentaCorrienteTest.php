<?php

namespace Tests\Feature;

use App\Exceptions\CajaException;
use App\Livewire\Pos\Caja as PantallaCaja;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Models\Cajero;
use App\Models\Cliente;
use App\Models\CobroCuentaCorriente;
use App\Models\Configuracion;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\CuentaCorrienteService;
use App\Services\DevolucionService;
use App\Services\SyncService;
use App\Services\TicketService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CuentaCorrienteTest extends TestCase
{
    use RefreshDatabase;

    private CuentaCorrienteService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        app(CajaService::class)->abrir('Ana', 1000);
        $this->cuentas = app(CuentaCorrienteService::class);
    }

    private function cliente(array $extra = []): Cliente
    {
        return Cliente::create(array_merge([
            'id' => 7, 'nombre' => 'Kiosco Don José', 'doc_tipo' => 80, 'documento' => '30712345671', 'condicion_iva' => 6,
            'cuenta_corriente' => true, 'limite_credito' => 10000, 'saldo' => 2000, 'sincronizado_at' => now()->subMinute(),
        ], $extra));
    }

    private function venderACuenta(Cliente $cliente, int $precio, ?int $aCuenta = null): Venta
    {
        $p = $this->producto(['precio' => $precio]);
        $aCuenta ??= $precio;
        $pagos = [['medio' => 'cuenta_corriente', 'monto' => $aCuenta]];

        if ($precio > $aCuenta) {
            $pagos[] = ['medio' => 'efectivo', 'monto' => $precio - $aCuenta];
        }

        return app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], $pagos, null, ['cliente_id' => $cliente->id]);
    }

    // ---- Sync y saldo -------------------------------------------------------------------

    public function test_sync_reemplaza_clientes_con_la_hora_de_la_caja_antes_de_pedir(): void
    {
        $this->cliente(['id' => 99, 'nombre' => 'Ya no existe']);
        $this->travelTo(now()->startOfMinute());

        Http::fake(['manager.fake/api/v1/sync/clientes' => function () {
            $this->travel(5)->seconds();

            return Http::response(['data' => [
                ['id' => 7, 'nombre' => 'Kiosco', 'doc_tipo' => 80, 'documento' => '30712345671', 'condicion_iva' => 6, 'cuenta_corriente' => true, 'limite_credito' => 5000, 'saldo' => 1234.5],
            ]]);
        }]);

        $inicio = now()->copy();
        $this->assertSame(['success' => true, 'cantidad' => 1], app(SyncService::class)->syncClientes());

        $cliente = Cliente::sole();
        $this->assertSame('1234.50', $cliente->saldo);
        $this->assertTrue($cliente->sincronizado_at->equalTo($inicio), 'marca la hora de antes del pedido, no la de la respuesta');
    }

    public function test_saldo_suma_lo_que_el_manager_todavia_no_tenia_al_armar_la_lista(): void
    {
        $cliente = $this->cliente(['saldo' => 2000, 'sincronizado_at' => now()->subMinutes(5)]);

        $noEnviada = $this->venderACuenta($cliente, 1500);
        $this->assertSame(350000, $this->cuentas->saldoCentavos($cliente));

        // Enviada antes de pedir la lista: ya está en el saldo del Manager.
        $noEnviada->update(['sincronizado' => true, 'sincronizado_at' => now()->subMinutes(10)]);
        $this->assertSame(200000, $this->cuentas->saldoCentavos($cliente));

        // Enviada después de pedirla: todavía no está, se suma.
        $noEnviada->update(['sincronizado_at' => now()->subMinute()]);
        $this->assertSame(350000, $this->cuentas->saldoCentavos($cliente));

        $this->cuentas->cobrar($cliente, 500, 'efectivo', app(CajaService::class)->turnoAbierto());
        $this->assertSame(300000, $this->cuentas->saldoCentavos($cliente));
    }

    // ---- Venta a cuenta ------------------------------------------------------------------

    public function test_limite_de_credito_y_reglas_de_venta_a_cuenta(): void
    {
        $cliente = $this->cliente(['saldo' => 9000]);

        try {
            $this->venderACuenta($cliente, 1500);
            $this->fail('Tenía que superar el límite');
        } catch (CajaException $e) {
            $this->assertStringContainsString('disponible $1.000,00', $e->getMessage());
        }

        // Parte a cuenta dentro del disponible y el resto en efectivo: pasa.
        $venta = $this->venderACuenta($cliente, 1500, 1000);
        $this->assertSame($cliente->id, $venta->cliente_id);
        $this->assertSame(0, $this->cuentas->disponibleCentavos($cliente));

        $sinCuenta = $this->cliente(['id' => 8, 'documento' => '20123456786', 'cuenta_corriente' => false]);
        $this->expectExceptionMessage('no tiene cuenta corriente');
        $this->venderACuenta($sinCuenta, 100);
    }

    public function test_venta_a_cuenta_sin_cliente_no_se_registra(): void
    {
        $p = $this->producto(['precio' => 100]);

        $this->expectExceptionMessage('elegí el cliente');
        app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'cuenta_corriente', 'monto' => 100]]);
    }

    public function test_sin_limite_y_datos_del_cliente_en_la_venta_la_factura_y_el_envio(): void
    {
        Configuracion::set('facturacion', json_encode(['activa' => true, 'condicion_iva' => 'responsable_inscripto']));
        $cliente = $this->cliente(['limite_credito' => null, 'saldo' => 999999]);

        $venta = $this->venderACuenta($cliente, 50000);

        $this->assertSame(['Kiosco Don José', '30712345671', 6, 80], [$venta->cliente_nombre, $venta->receptor_doc_nro, $venta->receptor_condicion_iva, $venta->receptor_doc_tipo]);

        $payload = app(SyncService::class)->payloadVenta($venta);
        $this->assertSame(7, $payload['cliente_id']);
        $this->assertSame('cuenta_corriente', $payload['pagos'][0]['medio']);

        $html = app(TicketService::class)->htmlVenta($venta);
        $this->assertStringContainsString('Cargado a la cuenta corriente de Kiosco Don José', $html);
        $this->assertStringContainsString('Firma del cliente', $html);
    }

    // ---- Cobros y devoluciones -----------------------------------------------------------

    public function test_cobro_valida_deuda_entra_al_arqueo_y_se_envia(): void
    {
        $cliente = $this->cliente(['saldo' => 3000]);
        $turno = app(CajaService::class)->turnoAbierto();

        foreach ([[0, 'efectivo', 'mayor a cero'], [5000, 'efectivo', 'supera la deuda'], [100, 'cuenta_corriente', 'cómo paga']] as [$monto, $medio, $mensaje]) {
            try {
                $this->cuentas->cobrar($cliente, $monto, $medio, $turno);
                $this->fail("Tenía que rechazar {$monto} {$medio}");
            } catch (CajaException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->cuentas->cobrar($cliente, 1200, 'efectivo', $turno);
        $this->cuentas->cobrar($cliente, 800, 'transferencia', $turno);

        $resumen = app(CajaService::class)->resumen($turno);
        $this->assertEquals(2200, $resumen['efectivo']['esperado'], 'fondo 1000 + cobro en efectivo 1200');
        $this->assertEquals(1200, $resumen['efectivo']['cobros_cuenta_corriente']);
        $this->assertEquals(2000, $resumen['cuenta_corriente']['cobros']);
        $this->assertStringContainsString('Cobrado · Transferencia', app(TicketService::class)->htmlInforme($turno));

        $recibo = app(TicketService::class)->htmlCobro(CobroCuentaCorriente::first());
        foreach (['RECIBO CUENTA CORRIENTE', 'COB04-000001', 'Kiosco Don José', '$1.200,00', 'Saldo pendiente', '$1.000,00'] as $texto) {
            $this->assertStringContainsString($texto, $recibo, "falta «{$texto}»");
        }

        [$primero, $segundo] = CobroCuentaCorriente::orderBy('id')->get();
        Http::fake(['manager.fake/api/v1/sync/cobros-cuenta-corriente' => Http::response(['resultados' => [
            ['uuid' => $primero->uuid, 'status' => 'creado'],
            ['uuid' => $segundo->uuid, 'status' => 'cliente_inexistente'],
        ]])]);

        $this->assertSame(['success' => true, 'cantidad' => 1], app(SyncService::class)->pushCobrosCuentaCorriente());
        $this->assertTrue($primero->fresh()->sincronizado);
        $this->assertFalse($segundo->fresh()->sincronizado, 'lo rechazado sigue pendiente');
        Http::assertSent(fn (Request $r) => $r['cobros'][0]['cliente_id'] === 7 && $r['cobros'][0]['numero'] === 'COB04-000001');
    }

    public function test_devolucion_de_venta_a_cuenta_acredita_y_no_devuelve_efectivo(): void
    {
        $cliente = $this->cliente(['saldo' => 0, 'limite_credito' => null]);
        $venta = $this->venderACuenta($cliente, 3000);
        $linea = $venta->detalles->first();
        Cajero::create(['id' => 2, 'nombre' => 'Sup', 'rol' => 'supervisor', 'pin_hash' => Hash::make('9999')]);
        $supervisor = Cajero::find(2);

        try {
            app(DevolucionService::class)->registrar($venta, [$linea->id => 1], 'Falla', 'efectivo', $supervisor);
            $this->fail('Una venta a cuenta no devuelve efectivo');
        } catch (CajaException $e) {
            $this->assertStringContainsString('se acredita en la cuenta', $e->getMessage());
        }

        $devolucion = app(DevolucionService::class)->registrar($venta, [$linea->id => 1], 'Falla', 'medio_original', $supervisor);

        $this->assertSame(300000, $this->cuentas->creditoCentavos($devolucion));
        $this->assertSame(0, $this->cuentas->saldoCentavos($cliente));
    }

    // ---- Pantallas ----------------------------------------------------------------------

    public function test_pantalla_de_venta_elige_cliente_y_vende_a_cuenta(): void
    {
        $cliente = $this->cliente(['saldo' => 500]);
        $p = $this->producto(['precio' => 1000]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->call('abrirCobro')
            ->call('elegirMedio', 'cuenta_corriente')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'elegí un cliente'))
            ->set('clienteBusqueda', 'kiosco')
            ->assertSee('Kiosco Don José')
            ->call('elegirCliente', $cliente->id)
            ->assertSet('clienteNombre', 'Kiosco Don José')
            ->assertSee('disponible $9.500,00')
            ->call('elegirMedio', 'cuenta_corriente')
            ->assertSet('pagoMedio', 'cuenta_corriente')
            ->call('finalizarVenta')
            ->assertSet('error', null)
            ->assertSet('clienteId', null);

        $venta = Venta::sole();
        $this->assertSame(7, $venta->cliente_id);
        $this->assertSame(150000, $this->cuentas->saldoCentavos($cliente));
    }

    public function test_pantalla_de_caja_cobra_la_cuenta(): void
    {
        $cliente = $this->cliente(['saldo' => 2500]);

        Livewire::test(PantallaCaja::class)
            ->set('clienteBusquedaCobro', '30712')
            ->call('elegirClienteCobro', $cliente->id)
            ->assertSee('$2.500,00')
            ->set('cobroMonto', '1000')->set('cobroMedio', 'debito')
            ->call('cobrarCuenta')
            ->assertSet('error', null)
            ->assertSet('exito', fn ($m) => str_contains((string) $m, 'Debe $1.500,00'))
            ->assertSee('Imprimir último recibo');

        $this->assertSame('debito', CobroCuentaCorriente::sole()->medio);
    }
}
