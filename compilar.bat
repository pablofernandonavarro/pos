@echo off
chcp 65001 >nul
echo ╔════════════════════════════════════════════════════╗
echo ║   COMPILADOR NATIVEPHP - POS SYSTEM                ║
echo ╚════════════════════════════════════════════════════╝
echo.

REM Verificar que estamos en el directorio correcto
if not exist "composer.json" (
    echo ❌ ERROR: No se encuentra composer.json
    pause
    exit /b 1
)

echo 📦 Preparando compilación...
echo.

REM Verificar NativePHP instalado
if not exist "vendor\nativephp" (
    echo ❌ ERROR: NativePHP no está instalado
    echo.
    echo Para instalar NativePHP:
    echo    composer require nativephp/electron
    echo    php artisan native:install electron
    echo.
    pause
    exit /b 1
)

REM Preguntar nombre del POS
echo 📝 ¿Para qué POS es esta compilación?
echo.
echo    1) Build genérico (configurable en instalación)
echo    2) Build específico para una caja
echo.
set /p OPCION="Selecciona una opción (1 o 2): "

if "%OPCION%"=="2" (
    set /p POS_NAME="Nombre del POS (ej: Caja 1): "
    set /p POS_ID="ID del POS (número): "

    echo.
    echo ✓ Compilando para: %POS_NAME% (ID: %POS_ID%)

    REM Crear .env temporal con configuración
    copy .env .env.backup >nul 2>&1
    echo APP_NAME="POS - %POS_NAME%">> .env.temp
    echo PUNTO_DE_VENTA_ID=%POS_ID%>> .env.temp
    type .env.temp > .env
    del .env.temp
) else (
    echo.
    echo ✓ Compilando build genérico
)

echo.
echo ════════════════════════════════════════════════════
echo   INICIANDO COMPILACIÓN
echo ════════════════════════════════════════════════════
echo.

REM Limpiar caché
echo [1/6] Limpiando caché...
php artisan optimize:clear >nul 2>&1
echo ✓ Caché limpiado

REM Compilar assets
echo.
echo [2/6] Compilando assets...
call npm run build >nul
if errorlevel 1 (
    echo ❌ ERROR: Fallo la compilación de assets
    pause
    exit /b 1
)
echo ✓ Assets compilados

REM Optimizar aplicación
echo.
echo [3/6] Optimizando aplicación...
php artisan optimize >nul
echo ✓ Aplicación optimizada

REM Verificar iconos
echo.
echo [4/6] Verificando recursos...
if not exist "resources\images\icon.png" (
    echo ⚠ ADVERTENCIA: No se encontró icon.png en resources\images\
    echo   La aplicación usará el icono por defecto
) else (
    echo ✓ Icono encontrado
)

REM Compilar con NativePHP
echo.
echo [5/6] Compilando aplicación nativa...
echo    (Esto puede tardar 5-10 minutos dependiendo de tu PC)
echo.

php artisan native:build windows
if errorlevel 1 (
    echo.
    echo ❌ ERROR: Fallo la compilación de NativePHP
    echo.
    echo Verifica:
    echo    • Node.js está instalado (node -v)
    echo    • npm está instalado (npm -v)
    echo    • Tienes espacio en disco suficiente (2+ GB)
    echo.

    REM Restaurar .env si era específico
    if exist ".env.backup" (
        move /y .env.backup .env >nul
    )

    pause
    exit /b 1
)

REM Renombrar si era específico
echo.
echo [6/6] Finalizando...
if "%OPCION%"=="2" (
    if exist "dist\POS System Setup.exe" (
        move "dist\POS System Setup.exe" "dist\POS-%POS_NAME%-Setup.exe" >nul
        echo ✓ Renombrado a: POS-%POS_NAME%-Setup.exe
    )

    REM Restaurar .env
    if exist ".env.backup" (
        move /y .env.backup .env >nul
    )
)

echo.
echo ╔════════════════════════════════════════════════════╗
echo ║   ✅ COMPILACIÓN COMPLETADA                        ║
echo ╚════════════════════════════════════════════════════╝
echo.
echo 📁 Ubicación del instalador:
echo ────────────────────────────────────────────────────

if "%OPCION%"=="2" (
    set INSTALLER_NAME=POS-%POS_NAME%-Setup.exe
) else (
    set INSTALLER_NAME=POS System Setup.exe
)

if exist "dist\%INSTALLER_NAME%" (
    echo    %CD%\dist\%INSTALLER_NAME%
    echo.

    for %%A in ("dist\%INSTALLER_NAME%") do set TAMANO=%%~zA
    set /a TAMANO_MB=%TAMANO% / 1048576
    echo 📊 Tamaño: %TAMANO_MB% MB
) else (
    echo    ⚠ No se encontró el instalador en dist\
)

echo.
echo 📦 DISTRIBUCIÓN:
echo ────────────────────────────────────────────────────
echo    • Copia el instalador a USB o servidor
echo    • Distribuye a cada computadora
echo    • Ejecutar con doble click
echo.
echo 💡 TIPS:
echo ────────────────────────────────────────────────────
echo    • El instalador preserva datos en actualizaciones
echo    • Tamaño típico: 150-250 MB
echo    • Compatible con Windows 7/8/10/11
echo.
echo ════════════════════════════════════════════════════
pause
