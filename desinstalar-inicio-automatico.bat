@echo off
chcp 65001 >nul
title POS - Quitar inicio automatico
echo ════════════════════════════════════════════════════
echo   POS - QUITAR INICIO AUTOMATICO
echo ════════════════════════════════════════════════════
echo.
echo Elimina las tareas programadas de ESTA instalacion y
echo detiene sus procesos. La sincronizacion automatica deja
echo de funcionar hasta que la levantes con servicios.bat.
echo.
pause

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0desinstalar-inicio-automatico.ps1" -Carpeta "%~dp0."

echo.
pause
