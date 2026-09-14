<?php

namespace App\Contracts;

/**
 * Impresión de tickets e informes. En la app de escritorio imprime directo en la
 * impresora elegida (Epson TM-T20) sin diálogo; en la instalación clásica no puede, y la
 * pantalla ofrece imprimir desde el navegador.
 */
interface ImpresoraTickets
{
    /** Si esta instalación puede imprimir sin diálogo. */
    public function puedeImprimirDirecto(): bool;

    /** @return array<int, string> Nombres de las impresoras instaladas. */
    public function impresoras(): array;

    /**
     * @return string|null null si se imprimió; si no, el motivo para mostrar.
     */
    public function imprimir(string $html, string $impresora): ?string;
}
