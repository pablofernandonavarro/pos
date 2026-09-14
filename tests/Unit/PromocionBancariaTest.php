<?php

namespace Tests\Unit;

use App\Models\PromocionBancaria;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromocionBancariaTest extends TestCase
{
    private function promo(array $datos = []): PromocionBancaria
    {
        return new PromocionBancaria(array_merge([
            'id' => 1,
            'nombre' => 'Galicia 20%',
            'banco' => 'Banco Galicia',
            'medios' => ['credito'],
            'modalidad' => 'descuento',
            'porcentaje' => 20,
        ], $datos));
    }

    public function test_aplica_por_medio_tarjeta_banco_dia_vigencia_y_minimo(): void
    {
        $jueves = Carbon::parse('2026-09-17 15:00'); // jueves

        $p = $this->promo([
            'tarjetas' => ['visa'],
            'dias_semana' => [4],
            'vigencia_desde' => '2026-09-01',
            'vigencia_hasta' => '2026-09-30',
            'monto_minimo' => 5000,
        ]);

        $this->assertTrue($p->aplicaA('credito', 'visa', '  banco   GALICIA ', 500000, $jueves));

        $this->assertFalse($p->aplicaA('debito', 'visa', 'Banco Galicia', 500000, $jueves), 'otro medio');
        $this->assertFalse($p->aplicaA('credito', 'mastercard', 'Banco Galicia', 500000, $jueves), 'otra tarjeta');
        $this->assertFalse($p->aplicaA('credito', 'visa', 'Santander', 500000, $jueves), 'otro banco');
        $this->assertFalse($p->aplicaA('credito', 'visa', 'Banco Galicia', 500000, $jueves->copy()->addDay()), 'viernes');
        $this->assertFalse($p->aplicaA('credito', 'visa', 'Banco Galicia', 499999, $jueves), 'debajo del mínimo');
        $this->assertFalse($p->aplicaA('credito', 'visa', 'Banco Galicia', 500000, Carbon::parse('2026-10-01 10:00')), 'vencida');
        $this->assertFalse($p->aplicaA('credito', 'visa', 'Banco Galicia', 500000, Carbon::parse('2026-08-27 10:00')), 'todavía no empezó');
    }

    public function test_el_ultimo_dia_de_vigencia_aplica_hasta_la_noche(): void
    {
        $p = $this->promo(['banco' => null, 'vigencia_hasta' => '2026-09-30']);

        $this->assertTrue($p->aplicaA('credito', null, null, 1000, Carbon::parse('2026-09-30 23:59:00')));
        // Y consultar no altera la fecha guardada en el modelo
        $this->assertSame('2026-09-30 00:00:00', $p->vigencia_hasta->format('Y-m-d H:i:s'));
    }

    public function test_sin_banco_ni_tarjetas_aplica_a_cualquiera(): void
    {
        $p = $this->promo(['banco' => null, 'tarjetas' => null, 'dias_semana' => null]);

        $this->assertTrue($p->aplicaA('credito', 'naranja', 'Cualquiera', 100, now()));
    }

    public function test_descuento_con_tope_y_redondeo(): void
    {
        $this->assertSame(20000, $this->promo()->descuentoPara(100000));          // 20% de $1000
        $this->assertSame(333, $this->promo(['porcentaje' => 33.3])->descuentoPara(1000)); // redondeo al centavo
        $this->assertSame(50000, $this->promo(['tope' => 500])->descuentoPara(1000000)); // tope $500
    }

    public function test_reintegro_y_solo_cuotas_no_descuentan_en_caja(): void
    {
        $this->assertSame(0, $this->promo(['modalidad' => 'reintegro'])->descuentoPara(100000));
        $this->assertSame(0, $this->promo(['porcentaje' => 0, 'cuotas_sin_interes' => 6])->descuentoPara(100000));
    }

    public function test_texto_del_beneficio(): void
    {
        $this->assertSame('20% off · tope $5.000,00 · 6 cuotas s/interés', $this->promo(['tope' => 5000, 'cuotas_sin_interes' => 6])->beneficio());
        $this->assertSame('10% de reintegro', $this->promo(['modalidad' => 'reintegro', 'porcentaje' => 10])->beneficio());
    }
}
