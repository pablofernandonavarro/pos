<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Models\Cajero;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verificación de PIN de cajeros y supervisores.
 *
 * Con límite de intentos por cajero: un PIN de 4 dígitos son 10.000 combinaciones, que
 * se prueban en minutos si nada lo frena.
 */
class AutorizacionService
{
    private const INTENTOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    public function hayCajeros(): bool
    {
        return Cajero::exists();
    }

    public function verificar(?int $cajeroId, string $pin, bool $requiereSupervisor = false): Cajero
    {
        $cajero = $cajeroId ? Cajero::find($cajeroId) : null;

        if (! $cajero) {
            throw new CajaException($requiereSupervisor ? 'Elegí el supervisor que autoriza.' : 'Elegí el cajero.');
        }

        $clave = "pin-cajero:{$cajero->id}";

        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            throw new CajaException('Demasiados intentos con PIN incorrecto. Esperá '.RateLimiter::availableIn($clave).' segundos.');
        }

        if (! Hash::check($pin, $cajero->pin_hash)) {
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);

            throw new CajaException('PIN incorrecto.');
        }

        RateLimiter::clear($clave);

        if ($requiereSupervisor && ! $cajero->esSupervisor()) {
            throw new CajaException("{$cajero->nombre} no es supervisor: no puede autorizar esta operación.");
        }

        return $cajero;
    }
}
