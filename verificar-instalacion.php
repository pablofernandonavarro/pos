<?php

/**
 * Script de Verificación de Instalación del POS
 *
 * Ejecutar: php verificar-instalacion.php
 */

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "   VERIFICACIÓN DE INSTALACIÓN - POS SYSTEM\n";
echo "═══════════════════════════════════════════════════════\n\n";

$errores = 0;
$advertencias = 0;

// 1. Verificar versión de PHP
echo "✓ Verificando PHP...\n";
$phpVersion = phpversion();
if (version_compare($phpVersion, '8.2.0', '>=')) {
    echo "  ✓ PHP {$phpVersion} (OK)\n";
} else {
    echo "  ✗ PHP {$phpVersion} - Se requiere PHP 8.2 o superior\n";
    $errores++;
}

// 2. Verificar extensiones requeridas
echo "\n✓ Verificando extensiones PHP...\n";
$extensionesRequeridas = [
    'pdo_sqlite' => 'PDO SQLite',
    'openssl' => 'OpenSSL',
    'mbstring' => 'Multibyte String',
    'curl' => 'cURL',
    'json' => 'JSON',
];

foreach ($extensionesRequeridas as $extension => $nombre) {
    if (extension_loaded($extension)) {
        echo "  ✓ {$nombre}\n";
    } else {
        echo "  ✗ {$nombre} - REQUERIDA\n";
        $errores++;
    }
}

// 3. Verificar Composer
echo "\n✓ Verificando Composer...\n";
if (file_exists('vendor/autoload.php')) {
    echo "  ✓ Dependencias de Composer instaladas\n";
} else {
    echo "  ✗ Dependencias de Composer NO instaladas\n";
    echo "     Ejecutar: composer install\n";
    $errores++;
}

// 4. Verificar .env
echo "\n✓ Verificando configuración...\n";
if (file_exists('.env')) {
    echo "  ✓ Archivo .env existe\n";

    $env = file_get_contents('.env');

    if (strpos($env, 'APP_KEY=base64:') !== false) {
        echo "  ✓ APP_KEY configurada\n";
    } else {
        echo "  ⚠ APP_KEY no configurada\n";
        echo "     Ejecutar: php artisan key:generate\n";
        $advertencias++;
    }

    if (strpos($env, 'DB_CONNECTION=sqlite') !== false) {
        echo "  ✓ Base de datos configurada como SQLite\n";
    } else {
        echo "  ⚠ Base de datos no configurada como SQLite\n";
        $advertencias++;
    }
} else {
    echo "  ✗ Archivo .env NO existe\n";
    echo "     Ejecutar: copy .env.example .env\n";
    $errores++;
}

// 5. Verificar base de datos SQLite
echo "\n✓ Verificando base de datos...\n";
$dbPath = 'database/database.sqlite';
if (file_exists($dbPath)) {
    echo "  ✓ Archivo database.sqlite existe\n";

    $filesize = filesize($dbPath);
    if ($filesize > 0) {
        echo "  ✓ Base de datos tiene contenido (" . number_format($filesize / 1024, 2) . " KB)\n";
    } else {
        echo "  ⚠ Base de datos está vacía\n";
        echo "     Ejecutar: php artisan migrate\n";
        $advertencias++;
    }
} else {
    echo "  ✗ Archivo database.sqlite NO existe\n";
    echo "     Windows: type nul > database\\database.sqlite\n";
    echo "     Linux/Mac: touch database/database.sqlite\n";
    $errores++;
}

// 6. Verificar permisos de escritura
echo "\n✓ Verificando permisos...\n";
$directoriosEscritura = [
    'storage/logs',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'bootstrap/cache',
];

foreach ($directoriosEscritura as $directorio) {
    if (is_writable($directorio)) {
        echo "  ✓ {$directorio} (escritura OK)\n";
    } else {
        echo "  ✗ {$directorio} - Sin permisos de escritura\n";
        $errores++;
    }
}

// 7. Verificar Node y NPM
echo "\n✓ Verificando Node.js y NPM...\n";
$node = shell_exec('node -v 2>&1');
$npm = shell_exec('npm -v 2>&1');

if ($node && strpos($node, 'v') === 0) {
    echo "  ✓ Node.js {$node}";

    if (file_exists('node_modules')) {
        echo "  ✓ Dependencias de NPM instaladas\n";
    } else {
        echo "  ⚠ Dependencias de NPM NO instaladas\n";
        echo "     Ejecutar: npm install\n";
        $advertencias++;
    }
} else {
    echo "  ⚠ Node.js no detectado\n";
    echo "     Instalar desde: https://nodejs.org/\n";
    $advertencias++;
}

// 8. Verificar assets compilados
echo "\n✓ Verificando assets compilados...\n";
if (file_exists('public/build/manifest.json')) {
    echo "  ✓ Assets compilados\n";
} else {
    echo "  ⚠ Assets NO compilados\n";
    echo "     Ejecutar: npm run build\n";
    $advertencias++;
}

// 9. Verificar conectividad (opcional)
echo "\n✓ Verificando conectividad...\n";
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    if (isset($env['MANAGER_API_URL'])) {
        echo "  ✓ MANAGER_API_URL configurada: {$env['MANAGER_API_URL']}\n";
    } else {
        echo "  ⚠ MANAGER_API_URL no configurada\n";
        echo "     Configurar en .env o via interfaz web\n";
        $advertencias++;
    }
}

// Resumen
echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "   RESUMEN DE VERIFICACIÓN\n";
echo "═══════════════════════════════════════════════════════\n";

if ($errores === 0 && $advertencias === 0) {
    echo "\n✅ ¡TODO PERFECTO! El POS está listo para usarse.\n";
    echo "\n   Iniciar servidor: php artisan serve\n";
    echo "   Luego ir a: http://localhost:8000/configuracion\n\n";
} elseif ($errores === 0) {
    echo "\n⚠️  Instalación funcional con {$advertencias} advertencia(s).\n";
    echo "   Puedes iniciar el servidor: php artisan serve\n";
    echo "   Recomendamos revisar las advertencias antes.\n\n";
} else {
    echo "\n❌ Se encontraron {$errores} error(es) crítico(s).\n";
    if ($advertencias > 0) {
        echo "   También hay {$advertencias} advertencia(s).\n";
    }
    echo "\n   Por favor, corrige los errores antes de continuar.\n\n";
}

echo "═══════════════════════════════════════════════════════\n\n";

exit($errores > 0 ? 1 : 0);
