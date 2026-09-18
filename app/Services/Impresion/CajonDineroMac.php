<?php

namespace App\Services\Impresion;

use App\Contracts\CajonDinero;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Abre el cajón mandando el mismo pulso ESC/POS que en Windows, en crudo por la cola de
 * CUPS (`lp -o raw`): el driver no rasteriza nada y los bytes llegan tal cual a la
 * impresora. El nombre que guarda Ajustes es el `name` de Electron, que en macOS es el de
 * la cola de CUPS, así que se pasa directo a `-d`.
 */
class CajonDineroMac implements CajonDinero
{
    public function disponible(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public function abrir(string $impresora): ?string
    {
        if (trim($impresora) === '') {
            return 'No hay impresora configurada (Ajustes → Impresora): el cajón se abre por ella.';
        }

        if (! $this->disponible()) {
            return 'Abrir el cajón por CUPS solo funciona en Mac.';
        }

        $proceso = new Process(self::comando($impresora));
        $proceso->setInput(self::pulso());
        $proceso->setTimeout(20);

        try {
            $proceso->run();
        } catch (\Throwable $e) {
            Log::error('No se pudo abrir el cajón', ['impresora' => $impresora, 'error' => $e->getMessage()]);

            return 'No se pudo abrir el cajón: '.$e->getMessage();
        }

        if ($proceso->isSuccessful()) {
            return null;
        }

        $detalle = trim($proceso->getErrorOutput()) ?: trim($proceso->getOutput());
        Log::warning('El cajón no abrió', ['impresora' => $impresora, 'salida' => $detalle]);

        return "No se pudo abrir el cajón por «{$impresora}». Revisá que la impresora esté encendida y el cajón conectado.";
    }

    /**
     * Argumentos sueltos, sin shell: el nombre de la impresora no se interpreta.
     *
     * @return array<int, string>
     */
    public static function comando(string $impresora): array
    {
        return ['/usr/bin/lp', '-d', $impresora, '-o', 'raw'];
    }

    public static function pulso(): string
    {
        return implode('', array_map('chr', CajonDineroWindows::PULSO));
    }
}
