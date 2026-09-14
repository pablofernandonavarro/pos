<?php

namespace App\Services\Impresion;

use App\Contracts\ImpresoraTickets;
use Illuminate\Support\Facades\Log;

/**
 * App de escritorio: imprime por Electron (webContents.print silencioso).
 *
 * Solo se instancia dentro de NativePHP (ver AppServiceProvider): referencia clases del
 * paquete nativephp/desktop, que la instalación clásica no tiene.
 */
class ImpresoraNativePHP implements ImpresoraTickets
{
    public function puedeImprimirDirecto(): bool
    {
        return true;
    }

    public function impresoras(): array
    {
        try {
            return array_map(fn ($p) => $p->name, \Native\Desktop\Facades\System::printers());
        } catch (\Throwable $e) {
            Log::warning('No se pudieron listar las impresoras', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function imprimir(string $html, string $impresora): ?string
    {
        if (trim($impresora) === '') {
            return 'No hay impresora configurada (Ajustes → Impresora).';
        }

        try {
            // NativePHP arma `data:text/html,${html}` sin codificar: un "#" en el ticket
            // (ej. "Venta #12") cortaría el documento ahí. Se manda ya codificado.
            \Native\Desktop\Facades\System::print(
                rawurlencode($html),
                new \Native\Desktop\DataObjects\Printer($impresora, $impresora, '', []),
                [
                    'silent' => true,
                    'printBackground' => false,
                    'margins' => ['marginType' => 'none'],
                ]
            );

            return null;
        } catch (\Throwable $e) {
            Log::error('Falló la impresión del ticket', ['impresora' => $impresora, 'error' => $e->getMessage()]);

            return 'No se pudo imprimir en «'.$impresora.'». Revisá que esté encendida y con papel.';
        }
    }
}
