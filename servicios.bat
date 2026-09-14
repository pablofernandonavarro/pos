@echo off
chcp 65001 >nul
title POS - Servicios de sincronizacion
echo ════════════════════════════════════════════════════
echo   POS - SERVICIOS DE SINCRONIZACION
echo ════════════════════════════════════════════════════
echo.
echo Este script levanta los dos procesos que mantienen
echo la sincronizacion con el Manager funcionando.
echo.
echo Usalo cuando servis el POS con Herd (pos.test) u otro
echo servidor externo. Si arrancas con iniciar.bat NO hace
echo falta: ese script ya los levanta por su cuenta.
echo.

if not exist "vendor\autoload.php" (
    echo ERROR: El proyecto no esta instalado
    echo Ejecuta primero: instalar.bat
    pause
    exit /b 1
)

REM Cerrar instancias previas para no duplicar procesos.
taskkill /FI "WINDOWTITLE eq POS Worker*" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq POS Scheduler*" /T /F >nul 2>&1

echo [1/2] Worker: envia cada venta al Manager apenas se cierra.
start "POS Worker" /MIN php artisan queue:work --tries=3 --sleep=1

echo [2/2] Scheduler: trae el stock cada minuto y reintenta envios pendientes.
start "POS Scheduler" /MIN php artisan schedule:work

echo.
echo ✓ Servicios corriendo (minimizados en la barra de tareas).
echo.
echo Para detenerlos, cerra esta ventana y respondi que si.
echo.
pause

taskkill /FI "WINDOWTITLE eq POS Worker*" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq POS Scheduler*" /T /F >nul 2>&1
echo Servicios detenidos.
