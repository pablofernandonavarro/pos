<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Lo que en la instalación clásica hacen los .bat (acceso directo, arranque con Windows)
 * pero para la app de escritorio, donde no hay instalador de consola que lo haga.
 *
 * Todo es "mejor esfuerzo": si algo falla se registra y la caja queda instalada igual,
 * porque vender no depende de tener un acceso directo.
 */
class EscritorioService
{
    public static function esAppDeEscritorio(): bool
    {
        return (bool) config('nativephp-internal.running') && class_exists(\Native\Desktop\Facades\App::class);
    }

    /**
     * @return array<int, string> Lo que se pudo dejar listo, para mostrárselo al usuario.
     */
    public function prepararMaquina(string $nombreCaja): array
    {
        if (! self::esAppDeEscritorio()) {
            return [];
        }

        $hecho = [];

        try {
            // Electron registra el ejecutable en el inicio de sesión de Windows. Es lo que
            // reemplaza a las tareas programadas: la sincronización corre mientras la app
            // está abierta, así que tiene que abrirse sola al prender la máquina.
            \Native\Desktop\Facades\App::openAtLogin(true);
            $hecho[] = 'Se abre sola al iniciar Windows';
        } catch (\Throwable $e) {
            Log::warning('No se pudo activar el inicio automático', ['error' => $e->getMessage()]);
        }

        if ($this->crearAccesoDirecto($nombreCaja)) {
            $hecho[] = 'Acceso directo en el escritorio';
        }

        return $hecho;
    }

    /**
     * Aviso de Windows cuando aparece un remito en camino. La alerta que siempre está es
     * la del header; esta sirve para que se entere alguien que no está mirando la caja.
     *
     * @param  array<int, \App\Models\RemitoEntrante>  $remitos
     */
    public function notificarRemitosNuevos(array $remitos): void
    {
        if (! self::esAppDeEscritorio() || $remitos === []) {
            return;
        }

        $mensaje = count($remitos) === 1
            ? "Remito #{$remitos[0]->numero} desde {$remitos[0]->origen}: {$remitos[0]->total_unidades} unidades."
            : count($remitos).' remitos nuevos. Recibilos en la pantalla Remitos.';

        try {
            \Native\Desktop\Facades\Notification::title('Mercadería en camino')->message($mensaje)->show();
        } catch (\Throwable $e) {
            Log::warning('No se pudo mostrar la notificación de remito', ['error' => $e->getMessage()]);
        }
    }

    private function crearAccesoDirecto(string $nombreCaja): bool
    {
        $exe = self::rutaEjecutable();

        if (! $exe || PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        // Nombre y rutas viajan por variables de entorno y no interpolados en el script:
        // un nombre de caja con comillas o $ rompería (o inyectaría) el comando.
        $script = '$d = [Environment]::GetFolderPath("Desktop");'
            .' $s = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $d ("POS - " + $env:POS_NOMBRE + ".lnk")));'
            .' $s.TargetPath = $env:POS_EXE; $s.WorkingDirectory = $env:POS_DIR; $s.Save()';

        $nombre = preg_replace('/[\\\\\/:*?"<>|]/', '', $nombreCaja) ?: 'Caja';

        $resultado = Process::env([
            'POS_NOMBRE' => $nombre,
            'POS_EXE' => $exe,
            'POS_DIR' => dirname($exe),
        ])->timeout(30)->run(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);

        if (! $resultado->successful()) {
            Log::warning('No se pudo crear el acceso directo', ['error' => $resultado->errorOutput()]);

            return false;
        }

        return true;
    }

    /**
     * El PHP embebido vive en <app>\resources\build\php\php.exe, cuatro niveles por
     * debajo de la carpeta del ejecutable, tanto en la carpeta portable como instalado.
     */
    private static function rutaEjecutable(): ?string
    {
        $carpeta = dirname(PHP_BINARY, 4);

        $candidatos = array_filter(
            glob($carpeta.DIRECTORY_SEPARATOR.'*.exe') ?: [],
            fn (string $exe) => ! str_starts_with(strtolower(basename($exe)), 'uninstall')
        );

        return array_values($candidatos)[0] ?? null;
    }
}
