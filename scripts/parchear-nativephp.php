<?php

/**
 * Parche de nativephp/desktop para la app de escritorio. Lo corre composer después de
 * instalar con composer.escritorio.json (no hace falta Laravel).
 *
 * La 2.3.0 se publicó sin electron-plugin/dist/server/pdfPageSize.js: el plugin lo
 * importa y la app compilada no arranca (ventana en blanco, error de módulo en el log de
 * Electron). El archivo está compilado desde su pdfPageSize.ts. Si se actualiza el
 * paquete, verificar si la versión nueva ya lo trae y sacar este parche.
 */
$raiz = dirname(__DIR__);
$paquete = "{$raiz}/vendor/nativephp/desktop";

if (! is_dir($paquete)) {
    fwrite(STDOUT, "nativephp/desktop no está instalado: no hay nada que parchear.\n");
    exit(0);
}

$instalados = json_decode((string) file_get_contents("{$raiz}/vendor/composer/installed.json"), true);
$version = null;

foreach ($instalados['packages'] ?? $instalados as $paqueteInstalado) {
    if (($paqueteInstalado['name'] ?? null) === 'nativephp/desktop') {
        $version = ltrim((string) $paqueteInstalado['version'], 'v');
    }
}

$relativo = 'electron-plugin/dist/server/pdfPageSize.js';
$electron = "{$paquete}/resources/electron";
$destino = "{$electron}/{$relativo}";

// Dentro de la copia que NativePHP empaqueta el plugin de Electron viaja sin su dist (se
// usa el de la carpeta de compilación): ahí no hay nada que parchear.
if (! is_dir(dirname($destino))) {
    fwrite(STDOUT, "nativephp/desktop {$version}: esta copia no trae el proyecto Electron, sin parche.\n");
    exit(0);
}

if (is_file($destino)) {
    fwrite(STDOUT, "nativephp/desktop {$version}: {$relativo} ya existe, sin parche.\n");
    exit(0);
}

$origen = "{$raiz}/parches/nativephp-desktop-{$version}/{$relativo}";

if (! is_file($origen)) {
    fwrite(STDERR, "ERROR: nativephp/desktop {$version} no trae {$relativo} y no hay parche para esa versión.\n");
    fwrite(STDERR, "La app de escritorio no va a arrancar. Compilá el archivo desde su .ts o volvé a la 2.3.0.\n");
    exit(1);
}

if (! @copy($origen, $destino)) {
    fwrite(STDERR, "ERROR: no se pudo copiar el parche a {$destino}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp/desktop {$version}: aplicado el parche de {$relativo}.\n");
