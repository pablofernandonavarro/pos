<?php

namespace App\Services\Impresion;

use App\Contracts\CajonDinero;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Abre el cajón mandando `ESC p 0 25 250` en crudo a la impresora por la cola de Windows
 * (winspool, tipo de dato RAW).
 *
 * No se puede por la impresión de Electron: esa manda HTML ya rasterizado por el driver y
 * no deja pasar bytes ESC/POS. Tampoco hace falta compartir la impresora ni instalar nada:
 * PowerShell compila un helper mínimo con Add-Type. El script va con -EncodedCommand y no
 * como archivo, porque la app de escritorio no empaqueta los .ps1.
 */
class CajonDineroWindows implements CajonDinero
{
    /** ESC p m t1 t2: pulso en el pin 2 (m=0), 50 ms encendido, 500 ms apagado. */
    public const PULSO = [0x1B, 0x70, 0x00, 0x19, 0xFA];

    public function disponible(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public function abrir(string $impresora): ?string
    {
        if (trim($impresora) === '') {
            return 'No hay impresora configurada (Ajustes → Impresora): el cajón se abre por ella.';
        }

        if (! $this->disponible()) {
            return 'Abrir el cajón desde la caja solo funciona en Windows.';
        }

        $proceso = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', self::comandoCodificado($impresora)]);
        $proceso->setTimeout(20);

        try {
            $proceso->run();
        } catch (\Throwable $e) {
            Log::error('No se pudo abrir el cajón', ['impresora' => $impresora, 'error' => $e->getMessage()]);

            return 'No se pudo abrir el cajón: '.$e->getMessage();
        }

        if ($proceso->isSuccessful() && str_contains($proceso->getOutput(), 'OK')) {
            return null;
        }

        $detalle = trim($proceso->getErrorOutput()) ?: trim($proceso->getOutput());
        Log::warning('El cajón no abrió', ['impresora' => $impresora, 'salida' => $detalle]);

        return "No se pudo abrir el cajón por «{$impresora}». Revisá que la impresora esté encendida y el cajón conectado.";
    }

    /** Script de PowerShell en UTF-16LE y base64, como pide -EncodedCommand. */
    public static function comandoCodificado(string $impresora): string
    {
        return base64_encode(mb_convert_encoding(self::script($impresora), 'UTF-16LE', 'UTF-8'));
    }

    public static function script(string $impresora): string
    {
        // Comillas simples de PowerShell: la única secuencia especial es '' (comilla escapada).
        $nombre = str_replace("'", "''", $impresora);
        $bytes = implode(',', array_map(fn ($b) => '0x'.dechex($b), self::PULSO));

        return <<<POWERSHELL
            \$ErrorActionPreference = 'Stop'
            Add-Type -TypeDefinition @'
            using System;
            using System.Runtime.InteropServices;
            public static class PosCajon {
                [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
                public class DocInfo { public string Nombre; public string Salida; public string Tipo; }
                [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
                static extern bool OpenPrinter(string nombre, out IntPtr impresora, IntPtr defaults);
                [DllImport("winspool.drv", SetLastError = true)]
                static extern bool ClosePrinter(IntPtr impresora);
                [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
                static extern int StartDocPrinter(IntPtr impresora, int nivel, DocInfo doc);
                [DllImport("winspool.drv", SetLastError = true)]
                static extern bool EndDocPrinter(IntPtr impresora);
                [DllImport("winspool.drv", SetLastError = true)]
                static extern bool StartPagePrinter(IntPtr impresora);
                [DllImport("winspool.drv", SetLastError = true)]
                static extern bool EndPagePrinter(IntPtr impresora);
                [DllImport("winspool.drv", SetLastError = true)]
                static extern bool WritePrinter(IntPtr impresora, byte[] datos, int largo, out int escritos);
                public static void Enviar(string nombre, byte[] datos) {
                    IntPtr h;
                    if (!OpenPrinter(nombre, out h, IntPtr.Zero)) throw new Exception("No existe la impresora " + nombre + " (" + Marshal.GetLastWin32Error() + ")");
                    try {
                        var doc = new DocInfo { Nombre = "Abrir cajon", Tipo = "RAW" };
                        if (StartDocPrinter(h, 1, doc) == 0) throw new Exception("StartDocPrinter " + Marshal.GetLastWin32Error());
                        StartPagePrinter(h);
                        int escritos;
                        if (!WritePrinter(h, datos, datos.Length, out escritos) || escritos != datos.Length) throw new Exception("WritePrinter " + Marshal.GetLastWin32Error());
                        EndPagePrinter(h);
                        EndDocPrinter(h);
                    } finally { ClosePrinter(h); }
                }
            }
            '@
            [PosCajon]::Enviar('{$nombre}', [byte[]]@({$bytes}))
            Write-Output 'OK'
            POWERSHELL;
    }
}
