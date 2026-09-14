<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Services\CajaService;
use App\Services\EstadoCajaService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EstadoCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
    }

    public function test_el_reporte_refleja_lo_que_la_caja_tiene_sin_enviar(): void
    {
        Configuracion::set('ultima_sincronizacion_stock', '2026-09-13T21:56:03+00:00');
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 500]);
        app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 500]]);

        $r = app(EstadoCajaService::class)->reporte();

        $this->assertSame('2026-09-13T21:56:03+00:00', $r['ultima_sincronizacion_stock']);
        $this->assertFalse($r['catalogo_pendiente']);
        $this->assertSame(1, $r['ventas_pendientes']);
        $this->assertNotNull($r['venta_pendiente_mas_vieja']);
        $this->assertSame(0, $r['movimientos_pendientes'], 'los movimientos de venta viajan con la venta');
        $this->assertSame('Ana', $r['turno_abierto']['cajero']);
        $this->assertSame(0, $r['facturas_pendientes']);
    }

    public function test_pos_comandos_informa_el_estado_aunque_no_haya_ordenes(): void
    {
        Http::fake([
            'manager.fake/api/v1/pos/estado' => Http::response(['ok' => true]),
            'manager.fake/api/v1/pos/comandos' => Http::response(['data' => []]),
        ]);

        $this->artisan('pos:comandos')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pos/estado') && $r['generado_at'] !== null && $r->hasHeader('X-POS-Version'));
    }

    public function test_si_el_manager_no_responde_el_reporte_no_frena_las_ordenes(): void
    {
        Http::fake([
            'manager.fake/api/v1/pos/estado' => Http::failedConnection(),
            'manager.fake/api/v1/pos/comandos' => Http::response(['data' => []]),
        ]);

        $this->artisan('pos:comandos')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pos/comandos'));
    }
}
