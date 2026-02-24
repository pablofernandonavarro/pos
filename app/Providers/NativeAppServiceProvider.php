<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Window;
use Native\Laravel\Menu\Menu;

class NativeAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Configurar ventana principal
        Window::open()
            ->title(config('app.name', 'POS System'))
            ->width(1280)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(768)
            ->resizable(true)
            ->rememberState();

        // Menú de la aplicación
        Menu::new()
            ->appMenu()
            ->submenu('Archivo', [
                Menu::link('🔄 Sincronizar', route('pos.sync'))->key('r'),
                Menu::separator(),
                Menu::link('⚙️ Configuración', route('pos.configuracion'))->key(','),
                Menu::separator(),
                Menu::quit(),
            ])
            ->submenu('Ver', [
                Menu::link('🛒 Nueva Venta', route('pos.venta'))->key('n'),
                Menu::separator(),
                Menu::fullscreen(),
                Menu::devtools(),
            ])
            ->submenu('Ayuda', [
                Menu::link('📚 Documentación', 'https://github.com/tu-repo/pos-docs'),
                Menu::separator(),
                Menu::about(),
            ])
            ->register();
    }
}
