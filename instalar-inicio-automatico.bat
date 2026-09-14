@echo off
chcp 65001 >nul
title POS - Instalar inicio automatico
echo ════════════════════════════════════════════════════
echo   POS - INICIO AUTOMATICO CON WINDOWS
echo ════════════════════════════════════════════════════
echo.
echo Registra dos tareas programadas para que la sincronizacion
echo con el Manager arranque sola al iniciar sesion en Windows:
echo.
echo   - POS Sync Worker     envia cada venta apenas se cierra
echo   - POS Sync Scheduler  trae el stock cada minuto
echo.
echo No requiere permisos de administrador.
echo.
pause

if not exist "%~dp0vendor\autoload.php" (
    echo.
    echo ERROR: El proyecto no esta instalado en esta carpeta.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar-inicio-automatico.ps1" -Carpeta "%~dp0."

echo.
pause
