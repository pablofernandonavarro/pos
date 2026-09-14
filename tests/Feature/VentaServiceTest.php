<?php

namespace Tests\Feature;

use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\MovimientoStock;
use App\Models\PromocionBancaria;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VentaServiceTest extends TestCase
{
    use RefreshDatabase;

    private VentaService $ventas;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        $this->ventas = app(VentaService::class);
    }

    private function abrirCaja(): void
    {
        app(CajaService::class)->abrir('Ana', 5000);
    }

    private function galicia(array $datos = []): PromocionBancaria
    {
        return PromocionBancaria::create(array_merge([
            'id' => 7,
            'nombre' => 'Galicia 20%',
            'banco' => 'Galicia',
            'medios' => ['credito', 'debito'],
            'modalidad' => 'descuento',
            'porcentaje' => 20,
        ], $datos));
    }

    public function test_sin_caja_abierta_no_se_vende(): void
    {
        $p = $this->producto();

        $this->expectException(CajaException::class);
        $this->expectExceptionMessage('La caja está cerrada');

        $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);
    }

    public function test_venta_en_efectivo_con_vuelto(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 1250.50, 'stock' => 5]);

        $venta = $this->ventas->registrar(
            [['product_id' => $p->id, 'cantidad' => 2]],
            [['medio' => 'efectivo', 'monto' => 2501, 'recibido' => 3000]],
            null,
            ['nombre' => '  Juan  ', 'documento' => '']
        );

        $this->assertSame('2501.00', $venta->total);
        $this->assertSame('efectivo', $venta->metodo_pago);
        $this->assertSame('Ana', $venta->cajero);
        $this->assertSame('Juan', $venta->cliente_nombre);
        $this->assertNull($venta->cliente_documento);
        $this->assertSame('499.00', $venta->pagos->sole()->vuelto);
        $this->assertSame(3, $p->fresh()->stock);
        $this->assertSame(1, MovimientoStock::where('tipo', 'venta')->count());
    }

    public function test_pago_mixto_con_promocion_recalculada_en_el_servidor(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 10000]);
        $promo = $this->galicia(['tope' => 1500]);

        $venta = $this->ventas->registrar(
            [['product_id' => $p->id, 'cantidad' => 1]],
            [
                ['medio' => 'efectivo', 'monto' => 2000],
                // La pantalla podría mandar un descuento inventado: no se lee, se recalcula.
                ['medio' => 'credito', 'monto' => 8000, 'descuento' => 8000, 'tarjeta' => 'visa', 'banco' => 'GALICIA', 'cuotas' => 3, 'promocion_id' => $promo->id],
            ]
        );

        $credito = $venta->pagos->firstWhere('medio', 'credito');
        $this->assertSame('1500.00', $credito->descuento, '20% de 8000 = 1600, con tope 1500');
        $this->assertSame('6500.00', $credito->importe);
        $this->assertSame('Galicia 20%', $credito->promocion_nombre);
        $this->assertSame('10000.00', $venta->subtotal);
        $this->assertSame('1500.00', $venta->descuento);
        $this->assertSame('8500.00', $venta->total);
        $this->assertSame('mixto', $venta->metodo_pago);
    }

    public function test_el_cobro_tiene_que_cerrar_exacto(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 1000]);

        foreach ([[['medio' => 'efectivo', 'monto' => 999.99]], [['medio' => 'efectivo', 'monto' => 600], ['medio' => 'debito', 'monto' => 400.01]]] as $pagos) {
            try {
                $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], $pagos);
                $this->fail('Tenía que rechazar el cobro');
            } catch (CajaException $e) {
                $this->assertMatchesRegularExpression('/(faltan|supera)/', $e->getMessage());
            }
        }

        // Tres pagos que en float no suman exacto, en centavos sí
        $venta = $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [
            ['medio' => 'efectivo', 'monto' => 333.33], ['medio' => 'transferencia', 'monto' => 333.33], ['medio' => 'qr', 'monto' => 333.34],
        ]);
        $this->assertSame('1000.00', $venta->total);
        $this->assertSame(9, $p->fresh()->stock);
    }

    public function test_el_precio_y_el_stock_salen_de_la_base_no_del_carrito(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 1000, 'stock' => 2]);

        // Carrito alterado desde el navegador: precio 1 y cantidad mayor al stock
        try {
            $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 3, 'precio_unitario' => 1]], [['medio' => 'efectivo', 'monto' => 3]]);
            $this->fail('Tenía que rechazar por stock');
        } catch (CajaException $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->getMessage());
        }

        // Precio alterado con stock suficiente: el cobro de $1 no cubre los $2000 reales
        $this->expectException(CajaException::class);
        $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 2, 'precio_unitario' => 0.5]], [['medio' => 'efectivo', 'monto' => 1]]);
    }

    public function test_el_mismo_producto_en_dos_lineas_suma_para_el_stock(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['stock' => 3]);

        $this->expectExceptionMessage('Stock insuficiente');
        $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 2], ['product_id' => $p->id, 'cantidad' => 2]], [['medio' => 'efectivo', 'monto' => 4000]]);
    }

    public function test_promocion_que_no_aplica_se_rechaza_y_no_se_registra_nada(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 1000]);
        $promo = $this->galicia(['tarjetas' => ['mastercard'], 'cuotas_sin_interes' => 3]);

        $casos = [
            'otra tarjeta' => ['medio' => 'credito', 'monto' => 1000, 'tarjeta' => 'visa', 'banco' => 'Galicia', 'promocion_id' => $promo->id],
            'otro banco' => ['medio' => 'credito', 'monto' => 1000, 'tarjeta' => 'mastercard', 'banco' => 'Nación', 'promocion_id' => $promo->id],
            'efectivo' => ['medio' => 'efectivo', 'monto' => 1000, 'promocion_id' => $promo->id],
            'más cuotas que las de la promo' => ['medio' => 'credito', 'monto' => 1000, 'tarjeta' => 'mastercard', 'banco' => 'Galicia', 'cuotas' => 6, 'promocion_id' => $promo->id],
            'promo inexistente' => ['medio' => 'credito', 'monto' => 1000, 'tarjeta' => 'mastercard', 'banco' => 'Galicia', 'promocion_id' => 999],
        ];

        foreach ($casos as $caso => $pago) {
            try {
                $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [$pago]);
                $this->fail("Tenía que rechazar: {$caso}");
            } catch (CajaException) {
            }
        }

        $this->assertSame(0, Venta::count());
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_validaciones_de_los_pagos(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['precio' => 1000]);
        $items = [['product_id' => $p->id, 'cantidad' => 1]];

        foreach ([
            [['medio' => 'bitcoin', 'monto' => 1000]],
            [['medio' => 'efectivo', 'monto' => 0], ['medio' => 'efectivo', 'monto' => 1000]],
            [['medio' => 'efectivo', 'monto' => 1000, 'recibido' => 500]],
            [['medio' => 'credito', 'monto' => 1000, 'tarjeta' => 'inventada']],
            [],
        ] as $pagos) {
            try {
                $this->ventas->registrar($items, $pagos);
                $this->fail('Tenía que rechazar '.json_encode($pagos));
            } catch (CajaException) {
            }
        }

        $this->assertSame(0, Venta::count());
    }

    public function test_producto_no_vendible_o_inactivo_no_se_vende(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['activo' => false]);

        $this->expectExceptionMessage('no está disponible');
        $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);
    }

    public function test_calcular_pago_muestra_lo_mismo_que_se_registra(): void
    {
        $promo = $this->galicia(['modalidad' => 'reintegro']);

        $calculo = $this->ventas->calcularPago(['medio' => 'debito', 'monto' => 5000, 'tarjeta' => 'visa', 'banco' => 'galicia', 'cuotas' => 12, 'promocion_id' => $promo->id]);

        $this->assertSame(0, $calculo['descuento'], 'reintegro: se cobra completo');
        $this->assertSame(500000, $calculo['importe']);
        $this->assertNull($calculo['cuotas'], 'débito no tiene cuotas');
    }

    public function test_registrar_no_despacha_el_sync_eso_lo_hace_la_pantalla(): void
    {
        $this->abrirCaja();
        $p = $this->producto();

        $this->ventas->registrar([['product_id' => $p->id, 'cantidad' => 1]], [['medio' => 'efectivo', 'monto' => 1000]]);

        Queue::assertNotPushed(SincronizarPendientes::class);
    }
}
