@echo off
chcp 65001 >nul
title POS - Crear acceso directo
echo ════════════════════════════════════════════════════
echo   CREAR ACCESO DIRECTO EN EL ESCRITORIO
echo ════════════════════════════════════════════════════
echo.
echo Crea un acceso directo que abre esta caja en modo
echo aplicacion: ventana sin pestañas ni barra de direcciones.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0crear-acceso-directo.ps1" -Carpeta "%~dp0."

echo.
pause
