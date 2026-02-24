@echo off
chcp 65001 >nul
echo ╔════════════════════════════════════════════════════╗
echo ║   MODO DESARROLLO NATIVEPHP                        ║
echo ╚════════════════════════════════════════════════════╝
echo.

echo Iniciando aplicación en modo desarrollo...
echo.
echo Esto abrirá una ventana nativa de Electron con:
echo    • Hot reload activado
echo    • DevTools disponibles
echo    • Sin necesidad de compilar
echo.
echo Presiona Ctrl+C para detener
echo.
echo ════════════════════════════════════════════════════
echo.

php artisan native:serve
