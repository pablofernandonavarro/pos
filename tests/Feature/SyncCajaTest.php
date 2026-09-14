<?php

namespace Tests\Feature;

use App\Models\PromocionBancaria;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\SyncService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
    }

    public function test_la_venta_viaja_con_turno_cajero_cliente_y_pagos(): void
    {
        $turno = app(CajaService::class)->abrir('Ana', 1000);
        $p = $this->producto(['precio' => 3000]);
        PromocionBancaria::create(['id' => 9, 'nombre' => 'Débito 10%', 'medios' => ['debito'], 'porcentaje' => 10]);

        $venta = app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 1]], [
            ['medio' => 'efectivo', 'monto' => 1000],
            ['medio' => 'debito', 'monto' => 2000, 'tarjeta' => 'maestro', 'promocion_id' => 9, 'referencia' => 'Lote 12'],
        ], null, ['nombre' => 'Juan', 'documento' => '30111222']);

        Http::fake(fn (Request $r) => Http::response(['message' => 'ok', 'resultados' => collect($r['ventas'])->map(fn ($v) => ['uuid' => $v['uuid'], 'status' => 'creada'])->all()]));

        $this->assertSame(1, app(SyncService::class)->pushVentas()['cantidad']);

        Http::assertSent(function (Request $r) use ($turno, $venta) {
            $v = $r['ventas'][0];

            return str_ends_with($r->url(), '/sync/ventas')
                && $v['uuid'] === $venta->uuid
                && $v['turno_uuid'] === $turno->uuid
                && $v['cajero'] === 'Ana'
                && $v['cliente_documento'] === '30111222'
                && $v['metodo_pago'] === 'mixto'
                && count($v['pagos']) === 2
                && $v['pagos'][1]['promocion_id'] === 9
                && (float) $v['pagos'][1]['importe'] === 1800.0
                && $v['pagos'][1]['referencia'] === 'Lote 12'
                && $r->hasHeader('X-POS-Version');
        });

        $this->assertTrue($venta->fresh()->sincronizado);
    }

    public function test_las_ventas_se_envian_en_tandas_de_100(): void
    {
        $turno = app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['stock' => 1000]);

        // Se crean directo para no pagar 250 veces el costo del servicio
        for ($i = 0; $i < 250; $i++) {
            $venta = Venta::create(['turno_caja_id' => $turno->id, 'numero_venta' => "V{$i}", 'fecha' => now(), 'subtotal' => 1000, 'total' => 1000]);
            $venta->detalles()->create(['product_id' => $p->id, 'cantidad' => 1, 'precio_unitario' => 1000, 'subtotal' => 1000]);
        }

        Http::fake(fn (Request $r) => Http::response(['message' => 'ok', 'resultados' => collect($r['ventas'])->map(fn ($v) => ['uuid' => $v['uuid'], 'status' => 'creada'])->all()]));

        $this->assertSame(250, app(SyncService::class)->pushVentas()['cantidad']);

        Http::assertSentCount(3);
        $this->assertSame(0, Venta::pendientes()->count());
    }

    public function test_una_venta_que_el_manager_no_confirma_no_deja_el_envio_en_loop(): void
    {
        $turno = app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto();
        Venta::create(['turno_caja_id' => $turno->id, 'numero_venta' => 'V1', 'fecha' => now(), 'subtotal' => 1, 'total' => 1])
            ->detalles()->create(['product_id' => $p->id, 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1]);

        Http::fake(['*' => Http::response(['message' => 'ok', 'resultados' => []])]);

        $this->assertSame(0, app(SyncService::class)->pushVentas()['cantidad']);
        Http::assertSentCount(1);
    }

    public function test_el_turno_abierto_se_reenvia_y_el_cerrado_se_marca_al_confirmarse(): void
    {
        $caja = app(CajaService::class);
        $turno = $caja->abrir('Ana', 2000);
        $caja->registrarMovimiento($turno, 'retiro', 500, 'Depósito');

        $estado = 'abierto';
        Http::fake(function (Request $r) use (&$estado) {
            return Http::response(['resultados' => collect($r['turnos'])->map(fn ($t) => ['uuid' => $t['uuid'], 'status' => $estado])->all()]);
        });

        $sync = app(SyncService::class);

        $this->assertSame(0, $sync->pushTurnos()['cantidad']);
        $this->assertFalse($turno->fresh()->sincronizado, 'el abierto nunca se marca');

        Http::assertSent(fn (Request $r) => $r['turnos'][0]['estado'] === 'abierto'
            && (float) $r['turnos'][0]['efectivo_esperado'] === 1500.0
            && $r['turnos'][0]['movimientos'][0]['motivo'] === 'Depósito'
            && $r['turnos'][0]['cerrado_at'] === null);

        $caja->cerrar($turno, 1500);
        $estado = 'cerrado';

        $this->assertSame(1, $sync->pushTurnos()['cantidad']);
        $this->assertTrue($turno->fresh()->sincronizado);

        Http::assertSent(fn (Request $r) => $r['turnos'][0]['estado'] === 'cerrado'
            && (float) $r['turnos'][0]['diferencia'] === 0.0
            && $r['turnos'][0]['resumen']['efectivo']['contado'] == 1500);

        // Ya confirmado: no se vuelve a mandar
        Http::fake(fn () => throw new \RuntimeException('no debería llamar'));
        $this->assertSame(0, $sync->pushTurnos()['cantidad']);
    }

    public function test_si_el_manager_ya_tenia_el_cierre_igual_se_marca(): void
    {
        $caja = app(CajaService::class);
        $turno = $caja->abrir('Ana', 0);
        $caja->cerrar($turno, 0);

        Http::fake(fn (Request $r) => Http::response(['resultados' => [['uuid' => $turno->uuid, 'status' => 'duplicado']]]));

        app(SyncService::class)->pushTurnos();

        $this->assertTrue($turno->fresh()->sincronizado);
    }

    public function test_sin_conexion_el_turno_queda_pendiente(): void
    {
        $caja = app(CajaService::class);
        $turno = $caja->abrir('Ana', 0);
        $caja->cerrar($turno, 0);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));

        $this->assertFalse(app(SyncService::class)->pushTurnos()['success']);
        $this->assertSame(1, TurnoCaja::pendientes()->count());
    }

    public function test_las_promociones_locales_se_reemplazan_por_las_del_manager(): void
    {
        PromocionBancaria::create(['id' => 1, 'nombre' => 'Vieja', 'medios' => ['credito'], 'porcentaje' => 5]);
        PromocionBancaria::create(['id' => 2, 'nombre' => 'Sigue', 'medios' => ['credito'], 'porcentaje' => 5]);

        Http::fake(['*/sync/promociones' => Http::response(['data' => [
            ['id' => 2, 'nombre' => 'Sigue editada', 'banco' => 'Galicia', 'medios' => ['credito', 'debito'], 'tarjetas' => ['visa'], 'dias_semana' => [4], 'vigencia_desde' => null, 'vigencia_hasta' => '2026-12-31', 'modalidad' => 'descuento', 'porcentaje' => 15, 'tope' => 3000, 'monto_minimo' => null, 'cuotas_sin_interes' => 6],
            ['id' => 3, 'nombre' => 'Nueva', 'banco' => null, 'medios' => ['qr'], 'tarjetas' => null, 'dias_semana' => null, 'vigencia_desde' => null, 'vigencia_hasta' => null, 'modalidad' => 'reintegro', 'porcentaje' => 10, 'tope' => null, 'monto_minimo' => null, 'cuotas_sin_interes' => null],
        ]])]);

        $r = app(SyncService::class)->syncPromociones();

        $this->assertSame(2, $r['cantidad']);
        $this->assertEqualsCanonicalizing([2, 3], PromocionBancaria::pluck('id')->all());
        $sigue = PromocionBancaria::find(2);
        $this->assertSame('Sigue editada', $sigue->nombre);
        $this->assertSame(['visa'], $sigue->tarjetas);
        $this->assertSame(6, $sigue->cuotas_sin_interes);
    }

    public function test_sin_conexion_se_conservan_las_promociones_que_habia(): void
    {
        PromocionBancaria::create(['id' => 1, 'nombre' => 'Guardada', 'medios' => ['credito'], 'porcentaje' => 5]);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));

        $this->assertFalse(app(SyncService::class)->syncPromociones()['success']);
        $this->assertSame(1, PromocionBancaria::count());
    }
}
