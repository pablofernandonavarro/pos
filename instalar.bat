@echo off
echo ========================================
echo   INSTALADOR POS SYSTEM
echo ========================================
echo.

REM Verificar que estamos en el directorio correcto
if not exist "composer.json" (
    echo ERROR: No se encuentra composer.json
    echo Por favor ejecuta este script desde el directorio del proyecto POS
    pause
    exit /b 1
)

echo [1/6] Verificando PHP...
php -v > nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP no encontrado en el PATH
    echo.
    echo Opciones:
    echo 1. Instala Laravel Herd: https://herd.laravel.com
    echo 2. Instala XAMPP: https://www.apachefriends.org
    echo 3. Agrega PHP al PATH del sistema
    echo.
    pause
    exit /b 1
)
php -v
echo.

echo [2/6] Instalando dependencias PHP (composer install)...
call composer install
if errorlevel 1 (
    echo ERROR: Fallo composer install
    pause
    exit /b 1
)
echo ✓ Dependencias PHP instaladas
echo.

echo [3/6] Verificando Node.js...
node -v > nul 2>&1
if errorlevel 1 (
    echo ERROR: Node.js no encontrado
    echo Instala Node.js desde: https://nodejs.org
    pause
    exit /b 1
)
node -v
echo.

echo [4/6] Instalando dependencias JavaScript (npm install)...
call npm install
if errorlevel 1 (
    echo ERROR: Fallo npm install
    pause
    exit /b 1
)
echo ✓ Dependencias JavaScript instaladas
echo.

echo [5/6] Configurando entorno...

REM Copiar .env si no existe
if not exist ".env" (
    copy .env.example .env
    echo ✓ Archivo .env creado
) else (
    echo ✓ Archivo .env ya existe
)

REM Generar APP_KEY si no existe
findstr /C:"APP_KEY=base64:" .env > nul
if errorlevel 1 (
    php artisan key:generate
    echo ✓ APP_KEY generada
) else (
    echo ✓ APP_KEY ya existe
)

REM Crear base de datos SQLite
if not exist "database\database.sqlite" (
    type nul > database\database.sqlite
    echo ✓ Base de datos SQLite creada
) else (
    echo ✓ Base de datos SQLite ya existe
)
echo.

echo [6/6] Ejecutando migraciones...
php artisan migrate --force
if errorlevel 1 (
    echo ERROR: Fallo en las migraciones
    pause
    exit /b 1
)
echo ✓ Migraciones ejecutadas
echo.

echo [7/7] Compilando assets...
call npm run build
if errorlevel 1 (
    echo ERROR: Fallo la compilación de assets
    pause
    exit /b 1
)
echo ✓ Assets compilados
echo.

echo ========================================
echo   INSTALACION COMPLETADA
echo ========================================
echo.
echo Siguiente paso:
echo   1. Ejecuta: php artisan serve
echo   2. Abre: http://localhost:8000/configuracion
echo.
echo Para verificar la instalación ejecuta:
echo   php verificar-instalacion.php
echo.
pause
