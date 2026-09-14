<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
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
