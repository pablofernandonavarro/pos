@echo off
chcp 65001 >nul
echo ════════════════════════════════════════════════════
echo   BACKUP DE BASE DE DATOS - POS SYSTEM
echo ════════════════════════════════════════════════════
echo.

REM Obtener nombre del POS
if exist ".pos-info" (
    set /p POS_NAME=<.pos-info
) else (
    set POS_NAME=POS
)

REM Crear directorio de backups si no existe
if not exist "backups" mkdir backups

REM Generar nombre de backup con fecha y hora
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set FECHA=%datetime:~0,8%
set HORA=%datetime:~8,6%
set BACKUP_NAME=backup-%POS_NAME%-%FECHA%-%HORA%.sqlite

REM Copiar base de datos
if exist "database\database.sqlite" (
    copy "database\database.sqlite" "backups\%BACKUP_NAME%" >nul
    echo ✓ Backup creado exitosamente
    echo.
    echo 📁 Ubicación: backups\%BACKUP_NAME%

    REM Obtener tamaño del archivo
    for %%A in ("backups\%BACKUP_NAME%") do set TAMANO=%%~zA
    set /a TAMANO_KB=%TAMANO% / 1024
    echo 📊 Tamaño: %TAMANO_KB% KB
    echo.

    REM Listar últimos 5 backups
    echo 📚 Últimos backups:
    echo ────────────────────────────────────────────────────
    dir /b /o-d backups\*.sqlite | findstr /n "^" | findstr /b "[1-5]:"
    echo.
) else (
    echo ❌ ERROR: No se encontró database\database.sqlite
    echo.
)

pause
