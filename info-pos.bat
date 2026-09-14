@echo off
chcp 65001 >nul
echo ════════════════════════════════════════════════════
echo   INFORMACIÓN DEL POS
echo ════════════════════════════════════════════════════
echo.

REM Leer configuración
if exist ".pos-info" (
    type .pos-info
    echo.
)

REM Leer .env
if exist ".env" (
    echo 📝 CONFIGURACIÓN:
    echo ────────────────────────────────────────────────────
    findstr /B "APP_NAME" .env
    findstr /B "MANAGER_API_URL" .env 2>nul
    findstr /B "PUNTO_DE_VENTA_ID" .env 2>nul
    echo.
)

REM Info de base de datos
if exist "database\database.sqlite" (
    echo 💾 BASE DE DATOS:
    echo ────────────────────────────────────────────────────
    for %%A in ("database\database.sqlite") do (
        set TAMANO=%%~zA
        set FECHA=%%~tA
    )
    set /a TAMANO_KB=%TAMANO% / 1024
    echo    Tamaño: %TAMANO_KB% KB
    echo    Modificado: %FECHA%
    echo.

    REM Contar registros (requiere PHP)
    php -r "$db = new PDO('sqlite:database/database.sqlite'); echo '   Productos: ' . $db->query('SELECT COUNT(*) FROM productos')->fetchColumn() . PHP_EOL; echo '   Ventas: ' . $db->query('SELECT COUNT(*) FROM ventas')->fetchColumn() . PHP_EOL; echo '   Ventas pendientes: ' . $db->query('SELECT COUNT(*) FROM ventas WHERE sincronizado = 0')->fetchColumn() . PHP_EOL;" 2>nul
    echo.
)

REM Info de versiones
echo 🔧 VERSIONES INSTALADAS:
echo ────────────────────────────────────────────────────
php -v | findstr "PHP"
node -v 2>nul && echo Node.js: || echo Node.js: No instalado
composer --version 2>nul | findstr "Composer" || echo Composer: No instalado
echo.

REM Info de red
echo 🌐 CONFIGURACIÓN DE RED:
echo ────────────────────────────────────────────────────
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    set IP=%%a
    goto :found
)
:found
echo    IP Local:%IP%
echo.

echo ════════════════════════════════════════════════════
pause
