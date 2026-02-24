@echo off
chcp 65001 >nul
echo ╔════════════════════════════════════════════════════╗
echo ║   COMPILADOR MÚLTIPLE - POS SYSTEM                 ║
echo ║   Compila todas las cajas automáticamente          ║
echo ╚════════════════════════════════════════════════════╝
echo.

echo Este script compilará múltiples builds personalizados.
echo.
echo Cada build incluirá:
echo    • Nombre del POS preconfigurado
echo    • ID del POS preconfigurado
echo    • Instalador independiente
echo.

REM Configuración de POS a compilar
REM EDITA ESTA LISTA según tus necesidades

echo ────────────────────────────────────────────────────
echo   CONFIGURACIÓN DE POS A COMPILAR
echo ────────────────────────────────────────────────────
echo.
echo Editando esta sección del script puedes configurar
echo los POS que deseas compilar:
echo.
echo    • POS 1: Caja 1
echo    • POS 2: Caja 2
echo    • POS 3: Caja 3
echo    • POS 4: Depósito
echo.
set /p CONTINUAR="¿Continuar con la compilación? (S/N): "

if /i not "%CONTINUAR%"=="S" (
    echo Compilación cancelada.
    pause
    exit /b 0
)

REM Hacer backup de .env
copy .env .env.backup >nul 2>&1

REM Array de POS (editar según necesidad)
set POS[1].NAME=Caja 1
set POS[1].ID=1

set POS[2].NAME=Caja 2
set POS[2].ID=2

set POS[3].NAME=Caja 3
set POS[3].ID=3

set POS[4].NAME=Deposito
set POS[4].ID=4

REM Cambiar esto según cuántos POS tienes
set TOTAL_POS=4

echo.
echo ════════════════════════════════════════════════════
echo   INICIANDO COMPILACIÓN MÚLTIPLE
echo ════════════════════════════════════════════════════
echo.

REM Compilar cada POS
for /L %%i in (1,1,%TOTAL_POS%) do (
    call :COMPILAR_POS %%i
)

REM Restaurar .env
if exist ".env.backup" (
    move /y .env.backup .env >nul
)

echo.
echo ╔════════════════════════════════════════════════════╗
echo ║   ✅ COMPILACIÓN MÚLTIPLE COMPLETADA               ║
echo ╚════════════════════════════════════════════════════╝
echo.
echo 📁 Instaladores creados en dist\:
echo ────────────────────────────────────────────────────
dir /b dist\POS-*.exe 2>nul
echo.
echo 📦 SIGUIENTE PASO:
echo    Distribuir cada instalador a su computadora correspondiente
echo.
pause
exit /b 0

REM Función para compilar un POS
:COMPILAR_POS
setlocal enabledelayedexpansion
set INDEX=%1
set NAME=!POS[%INDEX%].NAME!
set ID=!POS[%INDEX%].ID!

echo.
echo ┌────────────────────────────────────────────────────┐
echo │   Compilando: %NAME% (ID: %ID%)
echo └────────────────────────────────────────────────────┘
echo.

REM Crear .env temporal
echo APP_NAME="POS - %NAME%"> .env.temp
echo PUNTO_DE_VENTA_ID=%ID%>> .env.temp
type .env.backup >> .env.temp 2>nul
move /y .env.temp .env >nul

REM Limpiar y optimizar
echo [%INDEX%/%TOTAL_POS%] Optimizando...
php artisan optimize:clear >nul 2>&1
call npm run build >nul 2>&1
php artisan optimize >nul 2>&1

REM Compilar
echo [%INDEX%/%TOTAL_POS%] Compilando aplicación nativa...
php artisan native:build windows >nul 2>&1

REM Renombrar
if exist "dist\POS System Setup.exe" (
    move /y "dist\POS System Setup.exe" "dist\POS-%NAME%-Setup.exe" >nul 2>&1
    echo ✓ Compilado: POS-%NAME%-Setup.exe
) else (
    echo ✗ Error compilando: %NAME%
)

endlocal
exit /b 0
