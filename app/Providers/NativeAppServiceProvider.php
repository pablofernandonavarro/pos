<?php

namespace App\Providers;

use App\Support\VersionPos;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        // Constancia de cada apertura: sin esto, un silencio de la caja (app cerrada, PC suspendida)
        // no se distingue de un sync trabado. Va como warning porque el nivel de log de la
        // compilación de escritorio puede descartar info.
        Log::warning('POS iniciado', ['version' => VersionPos::actual()]);

        // Al abrir la app no puede haber ninguna tarea programada corriendo: si quedó un
        // candado de withoutOverlapping es de un cierre a mitad de un sync (apagar la PC,
        // actualizar la app) y sin esto frenaba esa tarea hasta que venciera.
        Artisan::call('schedule:clear-cache');

        Menu::create(
            Menu::app(),
            Menu::make(
                Menu::route('pos.sync', 'Sincronizar', 'CmdOrCtrl+R'),
                Menu::separator(),
                Menu::route('pos.configuracion', 'Configuración', 'CmdOrCtrl+,'),
                Menu::separator(),
                Menu::quit('Salir'),
            )->label('Archivo'),
            Menu::make(
                Menu::route('pos.venta', 'Nueva venta', 'CmdOrCtrl+N'),
                Menu::route('pos.ventas', 'Ventas y devoluciones', 'CmdOrCtrl+D'),
                Menu::route('pos.caja', 'Caja y cierre Z', 'CmdOrCtrl+K'),
                Menu::route('pos.remitos', 'Remitos por recibir'),
                Menu::separator(),
                Menu::fullscreen(),
                Menu::devTools(),
            )->label('Ver'),
        );

        Window::open()
            ->title(config('app.name', 'POS'))
            ->width(1280)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(768)
            ->rememberState();

        // No hace falta levantar un schedule:work: NativePHP ya corre `schedule:run` cada
        // minuto desde el lado de Electron, y la cola arranca sola (config queue_workers).
        // Agregar otro scheduler acá duplicaba cada tarea programada.
    }

    public function phpIni(): array
    {
        return [];
    }
}
