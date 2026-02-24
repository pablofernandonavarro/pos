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

php artisan serve
