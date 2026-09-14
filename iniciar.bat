@echo off
echo ========================================
echo   INICIANDO POS SYSTEM
echo ========================================
echo.

REM Verificar que está instalado
if not exist "vendor\autoload.php" (
    echo ERROR: El proyecto no está instalado
    echo Por favor ejecuta primero: instalar.bat
    pause
    exit /b 1
)

if not exist ".env" (
    echo ERROR: No existe el archivo .env
    echo Por favor ejecuta primero: instalar.bat
    pause
    exit /b 1
)

if not exist "database\database.sqlite" (
    echo ERROR: No existe la base de datos
    echo Por favor ejecuta primero: instalar.bat
    pause
    exit /b 1
)

echo Iniciando servidor Laravel...
echo.
echo El servidor se iniciara en: http://localhost:8000
echo.
echo Para configurar el POS:
echo   Abre: http://localhost:8000/configuracion
echo.
echo Presiona Ctrl+C para detener el servidor
echo.
echo ========================================
echo.

REM Worker: envia cada venta al Manager apenas se cierra (en segundos).
REM Sin esto las ventas quedan encoladas y solo salen cuando corre el scheduler.
echo Iniciando worker de sincronizacion...
start "POS Worker" /MIN php artisan queue:work --tries=3 --sleep=1

REM Scheduler: red de seguridad cada 5 minutos, por si el worker estuvo caido.
echo Iniciando sincronizacion programada...
start "POS Scheduler" /MIN php artisan schedule:work
echo.

php artisan serve

REM Al cerrar el servidor, cerrar tambien los procesos en segundo plano.
taskkill /FI "WINDOWTITLE eq POS Worker*" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq POS Scheduler*" /T /F >nul 2>&1
