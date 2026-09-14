<?php

namespace App\Support;

/**
 * Qué versión de la caja está corriendo, para informarla al Manager.
 *
 * - App de escritorio: NATIVEPHP_APP_VERSION, que queda fija en la compilación.
 * - Instalación clásica: el archivo VERSION que escribe pos:empaquetar y reemplaza
 *   pos:actualizar.
 */
class VersionPos
{
    public static function tipo(): string
    {
        // Por el paquete instalado y no por NATIVEPHP_RUNNING: los procesos de fondo
        // (cola, scheduler) también tienen que informar lo mismo que la ventana.
        return class_exists(\Native\Desktop\Facades\App::class) && config('nativephp.version')
            ? 'escritorio'
            : 'clasica';
    }

    public static function actual(): string
    {
        if (self::tipo() === 'escritorio') {
            return (string) config('nativephp.version');
        }

        $archivo = base_path('VERSION');

        return is_file($archivo) ? trim((string) file_get_contents($archivo)) : 'desarrollo';
    }

    /**
     * @return array{X-POS-Version: string, X-POS-Tipo: string}
     */
    public static function cabeceras(): array
    {
        return [
            'X-POS-Version' => self::actual(),
            'X-POS-Tipo' => self::tipo(),
        ];
    }
}
