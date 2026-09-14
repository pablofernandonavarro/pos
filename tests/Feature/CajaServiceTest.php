<?php

namespace Tests\Feature;

use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\PromocionBancaria;
use App\Models\TurnoCaja;
use App\Services\CajaService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CajaServiceTest extends TestCase
{
    use RefreshDatabase;

    private CajaService $caja;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        $this->caja = app(CajaService::class);
    }

    public function test_abrir_numera_y_no_permite_dos_cajas_abiertas(): void
    {
        $t1 = $this->caja->abrir('Ana', '5000.50');
        $this->assertSame(1, $t1->numero);
        $this->assertSame('5000.50', $t1->fondo_inicial);

        try {
            $this->caja->abrir('Beto', 0);
            $this->fail('Tenía que rechazar la segunda apertura');
        } catch (CajaException $e) {
            $this->assertStringContainsString('ya está abierta', $e->getMessage());
        }

        $this->caja->cerrar($t1, 5000.50);
        $this->assertSame(2, $this->caja->abrir('Beto', 0)->numero);
    }

    public function test_validaciones_de_apertura(): void
    {
        foreach ([['', 100], ['Ana', -1]] as [$cajero, $fondo]) {
            try {
                $this->caja->abrir($cajero, $fondo);
                $this->fail('Tenía que rechazar');
            } catch (CajaException) {
            }
        }

        $this->assertSame(0, TurnoCaja::count());
    }

    public function test_resumen_x_y_cierre_z_con_arqueo(): void
    {
        $turno = $this->caja->abrir('Ana', 5000);
        $ventas = app(VentaService::class);
        $p = $this->producto(['precio' => 1000, 'stock' => 50]);
        PromocionBancaria::create(['id' => 3, 'nombre' => 'QR 10%', 'medios' => ['qr'], 'porcentaje' => 10]);

        $ventas->registrar([['product_id' => $p->id, 'cantidad' => 2]], [['medio' => 'efectivo', 'monto' => 2000, 'recibido' => 5000]]);
        $ventas->registrar([['product_id' => $p->id, 'cantidad' => 3]], [['medio' => 'credito', 'monto' => 3000, 'tarjeta' => 'visa', 'cuotas' => 3]]);
        $ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'qr', 'monto' => 1000, 'promocion_id' => 3]]);

        $this->caja->registrarMovimiento($turno, 'ingreso', 500, 'Cambio');
        $this->caja->registrarMovimiento($turno, 'retiro', 1000, 'Depósito');
        $this->caja->registrarMovimiento($turno, 'gasto', 200, 'Artículos de limpieza');

        $x = $this->caja->resumen($turno);

        $this->assertSame(3, $x['ventas']['cantidad']);
        $this->assertSame(6, $x['ventas']['unidades']);
        $this->assertSame(6000.0, $x['ventas']['subtotal']);
        $this->assertSame(100.0, $x['ventas']['descuentos']);
        $this->assertSame(5900.0, $x['ventas']['total']);
        $this->assertSame(['credito' => 3000.0, 'efectivo' => 2000.0, 'qr' => 900.0], $x['por_medio']);
        $this->assertSame([['medio' => 'credito', 'tarjeta' => 'visa', 'cuotas' => 3, 'cantidad' => 1, 'importe' => 3000.0]], $x['tarjetas']);
        $this->assertSame([['nombre' => 'QR 10%', 'cantidad' => 1, 'descuento' => 100.0]], $x['promociones']);
        // 5000 fondo + 2000 efectivo + 500 ingreso − 1000 retiro − 200 gasto (el vuelto no cuenta)
        $this->assertSame(6300.0, $x['efectivo']['esperado']);

        $cerrado = $this->caja->cerrar($turno, 6250, '  Faltaron monedas ');

        $this->assertFalse($cerrado->estaAbierto());
        // El resumen guardado pasa por JSON: -50.0 vuelve como -50, se compara por valor.
        $this->assertEquals(-50, $cerrado->resumen['efectivo']['diferencia']);
        $this->assertEquals(6250, $cerrado->resumen['efectivo']['contado']);
        $this->assertSame('Faltaron monedas', $cerrado->observaciones);
        $this->assertNotNull($cerrado->resumen['turno']['cerrado_at']);
        Queue::assertPushed(SincronizarPendientes::class);
    }

    public function test_un_turno_cerrado_no_cambia_mas(): void
    {
        $turno = $this->caja->abrir('Ana', 1000);
        $this->caja->cerrar($turno, 1000);

        foreach ([
            fn () => $this->caja->cerrar($turno, 1),
            fn () => $this->caja->registrarMovimiento($turno->fresh(), 'ingreso', 100, 'x'),
        ] as $accion) {
            try {
                $accion();
                $this->fail('Tenía que rechazar');
            } catch (CajaException) {
            }
        }

        $this->assertSame('1000.00', $turno->fresh()->efectivo_contado);
    }

    public function test_no_se_retira_mas_efectivo_del_que_hay(): void
    {
        $turno = $this->caja->abrir('Ana', 1000);

        $this->expectExceptionMessage('No hay tanto efectivo');
        $this->caja->registrarMovimiento($turno, 'retiro', 1000.01, 'Depósito');
    }

    public function test_validaciones_de_movimientos(): void
    {
        $turno = $this->caja->abrir('Ana', 1000);

        foreach ([['robo', 10, 'x'], ['ingreso', 0, 'x'], ['ingreso', 10, '   ']] as [$tipo, $monto, $motivo]) {
            try {
                $this->caja->registrarMovimiento($turno, $tipo, $monto, $motivo);
                $this->fail("Tenía que rechazar {$tipo}");
            } catch (CajaException) {
            }
        }

        $this->assertSame(0, $turno->movimientos()->count());
    }

    public function test_turno_sin_ventas_se_puede_cerrar(): void
    {
        $turno = $this->caja->abrir('Ana', 0);
        $cerrado = $this->caja->cerrar($turno, 0);

        $this->assertSame(0, $cerrado->resumen['ventas']['cantidad']);
        $this->assertEquals(0, $cerrado->resumen['ventas']['ticket_promedio']);
        $this->assertEquals(0, $cerrado->resumen['efectivo']['diferencia']);
    }
}
