<?php

namespace App\Services\Impresion;

use App\Contracts\ImpresoraTickets;

/**
 * Instalación clásica (navegador): PHP no puede mandar a la impresora. La pantalla abre
 * el ticket en una pestaña con el diálogo de impresión.
 */
class ImpresoraNavegador implements ImpresoraTickets
{
    public function puedeImprimirDirecto(): bool
    {
        return false;
    }

    public function impresoras(): array
    {
        return [];
    }

    public function imprimir(string $html, string $impresora): ?string
    {
        return 'Esta instalación imprime desde el navegador.';
    }
}
