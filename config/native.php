<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | El nombre de tu aplicación nativa. Este valor se usa cuando el
    | framework necesita colocar el nombre de la aplicación en una
    | notificación o cualquier otra ubicación.
    |
    */

    'name' => env('APP_NAME', 'POS System'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | La versión de tu aplicación. Este valor se usa para el sistema de
    | actualizaciones y se muestra en el diálogo "Acerca de".
    |
    */

    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Application Author
    |--------------------------------------------------------------------------
    */

    'author' => 'Tu Empresa',

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Electron Settings
    |--------------------------------------------------------------------------
    |
    | Configuración específica para Electron
    |
    */

    'electron' => [
        'width' => 1280,
        'height' => 900,
        'minWidth' => 1024,
        'minHeight' => 768,
        'resizable' => true,
        'fullscreen' => false,
        'kiosk' => false,  // Cambiar a true para modo kiosk
        'alwaysOnTop' => false,
        'transparent' => false,
        'frame' => true,
        'backgroundColor' => '#0f172a',  // Slate-900
        'titleBarStyle' => 'default',
        'show' => true,
        'webPreferences' => [
            'nodeIntegration' => true,
            'contextIsolation' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Updater
    |--------------------------------------------------------------------------
    |
    | Configuración para actualizaciones automáticas
    |
    */

    'updater' => [
        'enabled' => env('NATIVE_UPDATER_ENABLED', false),
        'url' => env('NATIVE_UPDATER_URL'),
        'public_key' => env('NATIVE_UPDATER_PUBLIC_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Build Settings
    |--------------------------------------------------------------------------
    |
    | Configuración para el proceso de build
    |
    */

    'build' => [
        'appId' => 'com.tuempresa.pos',
        'productName' => env('APP_NAME', 'POS System'),
        'copyright' => 'Copyright © '.date('Y').' Tu Empresa',

        'mac' => [
            'category' => 'public.app-category.business',
            'icon' => 'resources/images/icon.icns',
            'target' => ['dmg', 'zip'],
        ],

        'win' => [
            'icon' => 'resources/images/icon.ico',
            'target' => [
                [
                    'target' => 'nsis',
                    'arch' => ['x64'],
                ],
            ],
        ],

        'linux' => [
            'icon' => 'resources/images/icon.png',
            'target' => ['AppImage', 'deb'],
            'category' => 'Office',
        ],

        'nsis' => [
            'oneClick' => false,
            'perMachine' => false,
            'allowToChangeInstallationDirectory' => true,
            'deleteAppDataOnUninstall' => false,
            'createDesktopShortcut' => true,
            'createStartMenuShortcut' => true,
            'shortcutName' => env('APP_NAME', 'POS System'),
        ],

        'files' => [
            '!**/*.map',
            '!node_modules',
            '!tests',
            '!storage/logs',
        ],

        'directories' => [
            'output' => 'dist',
        ],
    ],

];
