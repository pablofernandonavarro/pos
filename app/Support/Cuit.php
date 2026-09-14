<?php

namespace App\Support;

/**
 * CUIT/CUIL: once dígitos con dígito verificador módulo 11. Mismo cálculo que el Manager:
 * la caja lo valida antes de vender para no mandar una factura A que AFIP va a rechazar.
 */
final class Cuit
{
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function normalizar(?string $cuit): string
    {
        return preg_replace('/\D/', '', (string) $cuit);
    }

    public static function valido(?string $cuit): bool
    {
        $numero = self::normalizar($cuit);

        if (strlen($numero) !== 11) {
            return false;
        }

        $suma = 0;

        foreach (self::PESOS as $i => $peso) {
            $suma += (int) $numero[$i] * $peso;
        }

        $verificador = 11 - ($suma % 11);
        $verificador = match ($verificador) {
            11 => 0,
            10 => 9,
            default => $verificador,
        };

        return (int) $numero[10] === $verificador;
    }

    public static function formatear(?string $cuit): string
    {
        $numero = self::normalizar($cuit);

        return strlen($numero) === 11
            ? substr($numero, 0, 2).'-'.substr($numero, 2, 8).'-'.substr($numero, 10)
            : $numero;
    }
}
