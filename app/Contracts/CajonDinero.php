<?php

namespace App\Contracts;

/**
 * Cajón de dinero conectado a la impresora de tickets (puerto DK de la Epson TM-T20). Se
 * abre mandándole a la impresora el pulso ESC/POS.
 */
interface CajonDinero
{
    /** Si esta máquina puede mandar el pulso (Windows o Mac). */
    public function disponible(): bool;

    /** @return string|null null si se mandó el pulso; si no, el motivo. */
    public function abrir(string $impresora): ?string;
}
