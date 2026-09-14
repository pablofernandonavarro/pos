@echo off
chcp 65001 >nul
title POS - Preparar kit de instalacion
echo ════════════════════════════════════════════════════
echo   PREPARAR KIT PARA INSTALAR EN OTRA MAQUINA
echo ════════════════════════════════════════════════════
echo.
echo Genera una copia limpia de este POS, lista para llevar
echo a otra caja.
echo.
echo IMPORTANTE: no copies esta carpeta directamente. Lleva
echo la base de datos, el .env y la identidad de ESTA caja.
echo Si la copias tal cual, la maquina nueva va a creer que
echo es esta misma y va a sincronizar con su token.
echo.
pause

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0preparar-kit.ps1" -Origen "%~dp0."

echo.
pause
