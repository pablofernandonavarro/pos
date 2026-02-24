@echo off
chcp 65001 >nul
echo ════════════════════════════════════════════════════
echo   CREAR ACCESO DIRECTO EN ESCRITORIO
echo ════════════════════════════════════════════════════
echo.

REM Obtener nombre del POS
if exist ".pos-info" (
    set /p POS_NAME=<.pos-info
) else (
    set /p POS_NAME="Nombre del POS (ej: Caja 1): "
)

echo Creando acceso directo para: %POS_NAME%
echo.

REM Crear VBS para crear acceso directo
echo Set oWS = WScript.CreateObject("WScript.Shell") > CreateShortcut.vbs
echo sLinkFile = oWS.ExpandEnvironmentStrings("%%USERPROFILE%%\Desktop\POS - %POS_NAME%.lnk") >> CreateShortcut.vbs
echo Set oLink = oWS.CreateShortcut(sLinkFile) >> CreateShortcut.vbs
echo oLink.TargetPath = "%CD%\iniciar.bat" >> CreateShortcut.vbs
echo oLink.WorkingDirectory = "%CD%" >> CreateShortcut.vbs
echo oLink.Description = "POS - %POS_NAME%" >> CreateShortcut.vbs
echo oLink.IconLocation = "%%SystemRoot%%\System32\shell32.dll,165" >> CreateShortcut.vbs
echo oLink.Save >> CreateShortcut.vbs

REM Ejecutar VBS
cscript CreateShortcut.vbs >nul

REM Limpiar
del CreateShortcut.vbs

echo ✓ Acceso directo creado en el Escritorio
echo.
echo Nombre: POS - %POS_NAME%
echo.
echo Ahora puedes iniciar el POS con doble click desde el escritorio.
echo.
pause
