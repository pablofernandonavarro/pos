<?php

namespace App\Support;

/**
 * Las cuentas de plata se hacen en centavos enteros. Con floats, 0.1 + 0.2 no da 0.3 y
 * un cobro dividido en tres pagos puede quedar "debiendo" un centavo que no existe.
 */
final class Dinero
{
    public static function centavos(int|float|string|null $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    public static function pesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    public static function formato(int|float|string|null $pesos): string
    {
        return '$'.number_format((float) $pesos, 2, ',', '.');
    }
}
