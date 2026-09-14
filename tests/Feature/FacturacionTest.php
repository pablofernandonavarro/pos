<?php

namespace Tests\Feature;

use App\Contracts\ImpresoraTickets;
use App\Exceptions\CajaException;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Livewire\Pos\Ventas as PantallaVentas;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Models\MovimientoStock;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\DevolucionService;
use App\Services\FacturacionService;
use App\Services\Impresion\ImpresoraNavegador;
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

class FacturacionTest extends TestCase
{
    use RefreshDatabase;

    private const QR = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        app(CajaService::class)->abrir('Ana', 0);
    }

    private function activarFacturacion(string $condicion = 'responsable_inscripto'): void
    {
        Configuracion::set('facturacion', json_encode([
            'activa' => true, 'entorno' => 'homologacion', 'razon_social' => 'Zapatería SA', 'cuit' => '20-12345678-6',
            'condicion_iva' => $condicion, 'condicion_iva_nombre' => $condicion === 'responsable_inscripto' ? 'Responsable inscripto' : 'Monotributo',
            'ingresos_brutos' => '901-123456-7', 'inicio_actividades' => '2020-01-01', 'domicilio' => 'Av. Siempre Viva 742',
            'punto_venta' => 5,
        ]));
    }

    /** @param array<string, mixed> $extra */
    private static function comprobante(array $extra = []): array
    {
        return array_replace_recursive([
            'estado' => 'autorizado', 'tipo' => 6, 'nombre_tipo' => 'Factura B', 'letra' => 'B', 'codigo' => '006',
            'numero' => '00005-00000042', 'fecha' => now()->toDateString(), 'cae' => '76123456789012', 'cae_vencimiento' => now()->addDays(10)->toDateString(),
            'receptor' => ['doc_tipo' => 99, 'doc_tipo_nombre' => 'Sin identificar', 'doc_nro' => '0', 'nombre' => null, 'condicion_iva' => 'Consumidor final'],
            'importe_total' => 2420.0, 'importe_neto' => 2000.0, 'importe_iva' => 420.0,
            'alicuotas' => [['id' => 5, 'porcentaje' => 21.0, 'base' => 2000.0, 'importe' => 420.0]],
            'asociado' => null, 'qr_svg' => self::QR, 'error' => null,
        ], $extra);
    }

    private function vender(array $cliente = []): Venta
    {
        $p = $this->producto(['nombre' => 'Zapatillas', 'precio' => 1210]);

        return app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 2]], [['medio' => 'efectivo', 'monto' => 2420]], null, $cliente);
    }

    // ---- Sin facturación ---------------------------------------------------------

    public function test_sin_facturacion_activa_la_venta_no_se_factura_y_el_ticket_lo_aclara(): void
    {
        $venta = $this->vender(['documento' => '123']);

        $this->assertFalse($venta->facturar);
        $this->assertNull($venta->comprobante_estado);
        $this->assertArrayNotHasKey('factura', app(SyncService::class)->payloadVenta($venta));
        $this->assertNull(app(FacturacionService::class)->facturarAhora($venta));
        $this->assertStringContainsString('no válido como factura', app(TicketService::class)->htmlVenta($venta));
        Http::assertNothingSent();
    }

    // ---- Cliente -----------------------------------------------------------------

    public function test_datos_del_cliente_se_validan_antes_de_vender(): void
    {
        $this->activarFacturacion();

        $casos = [
            [['condicion_iva' => 1, 'documento' => '12345678', 'nombre' => 'Cliente SRL'], 'hace falta su CUIT'],
            [['condicion_iva' => 1, 'documento' => '30-71234567-2', 'nombre' => 'Cliente SRL'], 'CUIT del cliente no es válido'],
            [['condicion_iva' => 6, 'documento' => '30-71234567-1'], 'razón social'],
            [['condicion_iva' => 5, 'documento' => '12'], 'DNI'],
            [['condicion_iva' => 9], 'inválida'],
        ];

        foreach ($casos as [$cliente, $mensaje]) {
            try {
                $this->vender($cliente);
                $this->fail('Tenía que rechazar '.json_encode($cliente));
            } catch (CajaException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertSame(0, Venta::count(), 'con datos inválidos no se registra la venta');

        $venta = $this->vender(['condicion_iva' => 1, 'documento' => '30-71234567-1', 'nombre' => 'Cliente SRL']);
        $this->assertSame([1, 80, '30712345671'], [$venta->receptor_condicion_iva, $venta->receptor_doc_tipo, $venta->receptor_doc_nro]);
        $this->assertSame('A', FacturacionService::letraPara(1));
        $this->assertSame('B', FacturacionService::letraPara(5));

        $consumidor = $this->vender(['documento' => '28.123.456']);
        $this->assertSame([5, 96, '28123456'], [$consumidor->receptor_condicion_iva, $consumidor->receptor_doc_tipo, $consumidor->receptor_doc_nro]);

        $this->activarFacturacion('monotributo');
        $this->assertSame('C', FacturacionService::letraPara(1));
    }

    // ---- Factura en el momento ---------------------------------------------------

    public function test_con_conexion_la_factura_sale_en_el_momento_con_cae_y_qr(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();

        Http::fake(['manager.fake/api/v1/pos/facturas' => fn (Request $r) => Http::response([
            'venta' => ['uuid' => $r['uuid'], 'status' => 'creada', 'venta_id' => 9],
            'comprobante' => self::comprobante(),
        ])]);

        $this->assertNull(app(FacturacionService::class)->facturarAhora($venta));

        Http::assertSent(fn (Request $r) => $r['uuid'] === $venta->uuid && $r['factura']['condicion_iva'] === 5 && $r['factura']['doc_tipo'] === 99);

        $venta->refresh();
        $this->assertTrue($venta->facturaAutorizada());
        $this->assertTrue($venta->sincronizado, 'la venta llegó al Manager con la factura: no se vuelve a mandar');
        $this->assertSame(0, MovimientoStock::pendientes()->where('tipo', 'venta')->count());

        $html = app(TicketService::class)->htmlVenta($venta);
        foreach (['Zapatería SA', 'CUIT 20-12345678-6', 'Ingresos Brutos 901-123456-7', 'FACTURA B', 'Cód. 006', '00005-00000042', 'A consumidor final', 'IVA contenido', '$420,00', 'CAE Nº', '76123456789012', '<svg'] as $texto) {
            $this->assertStringContainsString($texto, $html, "falta «{$texto}»");
        }
        $this->assertStringNotContainsString('no válido como factura', $html);
    }

    public function test_factura_a_discrimina_iva_y_muestra_al_cliente(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender(['condicion_iva' => 1, 'documento' => '30712345671', 'nombre' => 'Cliente SRL']);
        $venta->update(['comprobante_estado' => 'autorizado', 'comprobante' => self::comprobante([
            'tipo' => 1, 'nombre_tipo' => 'Factura A', 'letra' => 'A', 'codigo' => '001',
            'receptor' => ['doc_tipo' => 80, 'doc_tipo_nombre' => 'CUIT', 'doc_nro' => '30712345671', 'nombre' => 'Cliente SRL', 'condicion_iva' => 'IVA Responsable inscripto'],
        ])]);

        $html = app(TicketService::class)->htmlVenta($venta->fresh());

        foreach (['FACTURA A', 'Cliente SRL', '30-71234567-1', 'IVA Responsable inscripto', 'Neto gravado 21%', '$2.000,00', 'IVA 21%', '$420,00'] as $texto) {
            $this->assertStringContainsString($texto, $html, "falta «{$texto}»");
        }
        $this->assertStringNotContainsString('IVA contenido', $html);
    }

    public function test_un_qr_con_codigo_no_se_imprime(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();
        $venta->update(['comprobante_estado' => 'autorizado', 'comprobante' => self::comprobante(['qr_svg' => '<svg onload="alert(1)"><script>alert(1)</script></svg>'])]);

        $html = app(TicketService::class)->htmlVenta($venta->fresh());

        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('CAE Nº', $html);
    }

    public function test_sin_conexion_la_venta_queda_con_factura_pendiente_y_se_avisa(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();
        Http::fake(['*' => Http::failedConnection()]);

        $aviso = app(FacturacionService::class)->facturarAhora($venta);

        $this->assertStringContainsString('Factura pendiente', $aviso);
        $venta->refresh();
        $this->assertSame('pendiente', $venta->comprobante_estado);
        $this->assertFalse($venta->sincronizado, 'sale después por el push normal, con el bloque factura');
        $this->assertArrayHasKey('factura', app(SyncService::class)->payloadVenta($venta));
        $this->assertStringContainsString('Factura en trámite', app(TicketService::class)->htmlVenta($venta));
    }

    public function test_con_el_catalogo_sin_verificar_no_se_manda_nada(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();
        Configuracion::where('clave', 'ultima_sincronizacion_productos')->delete();

        $this->assertStringContainsString('catálogo', app(FacturacionService::class)->facturarAhora($venta));
        Http::assertNothingSent();
    }

    public function test_pantalla_de_venta_factura_al_finalizar_y_frena_con_cuit_invalido(): void
    {
        $this->activarFacturacion();
        $p = $this->producto(['precio' => 1210]);

        Http::fake(['manager.fake/api/v1/pos/facturas' => fn (Request $r) => Http::response([
            'venta' => ['uuid' => $r['uuid'], 'status' => 'creada', 'venta_id' => 1],
            'comprobante' => self::comprobante(['tipo' => 1, 'nombre_tipo' => 'Factura A', 'letra' => 'A']),
        ])]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->set('clienteCondicionIva', '1')
            ->assertSee('Razón social')
            ->set('clienteNombre', 'Cliente SRL')->set('clienteDocumento', '30-71234567-2')
            ->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'CUIT del cliente no es válido'))
            ->set('clienteDocumento', '30-71234567-1')
            ->call('finalizarVenta')
            ->assertSet('error', null)
            ->assertSet('aviso', null)
            ->assertSet('clienteCondicionIva', '5');

        $this->assertTrue(Venta::sole()->facturaAutorizada());
    }

    // ---- Resultado en segundo plano y notas de crédito ---------------------------

    public function test_trae_el_resultado_de_facturas_y_notas_de_credito_pendientes(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();
        $venta->update(['sincronizado' => true, 'sincronizado_at' => now()]);

        $supervisor = Cajero::create(['id' => 2, 'nombre' => 'Sup', 'rol' => 'supervisor', 'pin_hash' => Hash::make('9999')]);
        $devolucion = app(DevolucionService::class)->registrar($venta, [$venta->detalles->first()->id => 1], 'Talle', 'efectivo', $supervisor);
        $devolucion->update(['sincronizado' => true]);

        // Una venta recién enviada sin comprobante todavía en el Manager: sigue esperando.
        $reciente = $this->vender();
        $reciente->update(['sincronizado' => true, 'sincronizado_at' => now()->subMinute()]);
        // Una que el Manager nunca facturó: deja de figurar pendiente.
        $vieja = $this->vender();
        $vieja->update(['sincronizado' => true, 'sincronizado_at' => now()->subMinutes(30)]);

        Http::fake(['manager.fake/api/v1/pos/comprobantes/estado' => Http::response([
            'ventas' => [$venta->uuid => self::comprobante()],
            'devoluciones' => [$devolucion->uuid => self::comprobante([
                'tipo' => 8, 'nombre_tipo' => 'Nota de crédito B', 'codigo' => '008', 'numero' => '00005-00000003',
                'importe_total' => 1210.0, 'asociado' => ['nombre_tipo' => 'Factura B', 'numero' => '00005-00000042'],
            ])],
        ])]);

        $r = app(FacturacionService::class)->actualizarPendientes();

        $this->assertSame(['success' => true, 'actualizados' => 3], $r);
        $this->assertTrue($venta->fresh()->facturaAutorizada());
        $this->assertSame('pendiente', $reciente->fresh()->comprobante_estado);
        $this->assertSame('rechazado', $vieja->fresh()->comprobante_estado);

        $html = app(TicketService::class)->htmlDevolucion($devolucion->fresh());
        foreach (['NOTA DE CRÉDITO B', '00005-00000003', 'Asociada a', 'Factura B 00005-00000042', 'CAE Nº'] as $texto) {
            $this->assertStringContainsString($texto, $html, "falta «{$texto}»");
        }
    }

    public function test_desde_ventas_se_pide_una_factura_pendiente(): void
    {
        $this->activarFacturacion();
        $venta = $this->vender();
        // Fija el tipo de impresión: con NativePHP instalado (compilación de escritorio) el
        // botón cambia de texto.
        $this->app->instance(ImpresoraTickets::class, new ImpresoraNavegador);

        Http::fake(['manager.fake/api/v1/pos/facturas' => fn (Request $r) => Http::response([
            'venta' => ['uuid' => $r['uuid'], 'status' => 'creada', 'venta_id' => 1],
            'comprobante' => self::comprobante(),
        ])]);

        Livewire::test(PantallaVentas::class)
            ->call('verDetalle', $venta->id)
            ->assertSee('Pedir factura ahora')
            ->call('facturar', $venta->id)
            ->assertSet('mensaje', 'Factura B 00005-00000042 autorizada.')
            // Instalación clásica: imprime desde el navegador.
            ->assertSee('Imprimir factura');
    }

    public function test_sync_trae_los_datos_del_emisor(): void
    {
        Http::fake(['manager.fake/api/v1/pos/facturacion' => Http::response(['activa' => true, 'condicion_iva' => 'monotributo', 'cuit' => '20-12345678-6'])]);

        $this->assertSame(['success' => true, 'activa' => true], app(SyncService::class)->syncFacturacion());
        $this->assertTrue(FacturacionService::activa());
        $this->assertSame('C', FacturacionService::letraPara(5));
    }
}
