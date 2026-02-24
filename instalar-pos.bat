@echo off
chcp 65001 >nul
echo ╔════════════════════════════════════════════════════╗
echo ║   INSTALADOR INTELIGENTE - POS SYSTEM             ║
echo ║   Instalación para Múltiples Cajas                ║
echo ╚════════════════════════════════════════════════════╝
echo.

REM Pedir información del POS
echo 📝 INFORMACIÓN DE ESTE PUNTO DE VENTA
echo ────────────────────────────────────────────────────
echo.

set /p POS_NAME="Nombre del POS (ej: Caja 1, Deposito): "
if "%POS_NAME%"=="" set POS_NAME=POS Sin Nombre

echo.
echo ✓ Este POS se llamará: %POS_NAME%
echo.
echo ────────────────────────────────────────────────────
pause
echo.

REM Verificar que estamos en el directorio correcto
if not exist "composer.json" (
    echo ❌ ERROR: No se encuentra composer.json
    echo Por favor ejecuta este script desde el directorio del proyecto POS
    echo.
    pause
    exit /b 1
)

echo ╔════════════════════════════════════════════════════╗
echo ║   INICIANDO INSTALACIÓN                            ║
echo ╚════════════════════════════════════════════════════╝
echo.

REM [1/8] Verificar PHP
echo [1/8] Verificando PHP...
php -v > nul 2>&1
if errorlevel 1 (
    echo ❌ ERROR: PHP no encontrado en el PATH
    echo.
    echo 📥 OPCIONES DE INSTALACIÓN:
    echo    1. Laravel Herd: https://herd.laravel.com
    echo    2. XAMPP: https://www.apachefriends.org
    echo    3. Agregar PHP al PATH del sistema
    echo.
    pause
    exit /b 1
)
for /f "tokens=2" %%i in ('php -v ^| findstr /R "PHP [0-9]"') do set PHP_VERSION=%%i
echo ✓ PHP %PHP_VERSION% detectado
echo.

REM [2/8] Verificar Composer
echo [2/8] Verificando Composer...
composer --version > nul 2>&1
if errorlevel 1 (
    echo ❌ ERROR: Composer no encontrado
    echo 📥 Descarga desde: https://getcomposer.org/download/
    echo.
    pause
    exit /b 1
)
echo ✓ Composer instalado
echo.

REM [3/8] Instalar dependencias PHP
echo [3/8] Instalando dependencias PHP...
echo    (Esto puede tardar 2-3 minutos)
call composer install --no-interaction --prefer-dist
if errorlevel 1 (
    echo ❌ ERROR: Fallo composer install
    echo.
    pause
    exit /b 1
)
echo ✓ Dependencias PHP instaladas
echo.

REM [4/8] Verificar Node.js
echo [4/8] Verificando Node.js...
node -v > nul 2>&1
if errorlevel 1 (
    echo ❌ ERROR: Node.js no encontrado
    echo 📥 Descarga desde: https://nodejs.org
    echo.
    pause
    exit /b 1
)
for /f "tokens=1" %%i in ('node -v') do set NODE_VERSION=%%i
echo ✓ Node.js %NODE_VERSION% detectado
echo.

REM [5/8] Instalar dependencias JavaScript
echo [5/8] Instalando dependencias JavaScript...
echo    (Esto puede tardar 2-3 minutos)
call npm install --silent
if errorlevel 1 (
    echo ❌ ERROR: Fallo npm install
    echo.
    pause
    exit /b 1
)
echo ✓ Dependencias JavaScript instaladas
echo.

REM [6/8] Configurar entorno
echo [6/8] Configurando entorno...

REM Copiar .env si no existe
if not exist ".env" (
    copy .env.example .env > nul
    echo ✓ Archivo .env creado

    REM Personalizar APP_NAME en .env
    powershell -Command "(Get-Content .env) -replace 'APP_NAME=\"POS System\"', 'APP_NAME=\"POS - %POS_NAME%\"' | Set-Content .env"
    echo ✓ Nombre del POS configurado: %POS_NAME%
) else (
    echo ⚠ Archivo .env ya existe (no modificado)
)

REM Generar APP_KEY si no existe
findstr /C:"APP_KEY=base64:" .env > nul 2>&1
if errorlevel 1 (
    php artisan key:generate --no-interaction
    echo ✓ APP_KEY generada
) else (
    echo ✓ APP_KEY ya existe
)

REM Crear base de datos SQLite
if not exist "database\database.sqlite" (
    type nul > database\database.sqlite
    echo ✓ Base de datos SQLite creada
) else (
    echo ✓ Base de datos SQLite ya existe
)
echo.

REM [7/8] Ejecutar migraciones
echo [7/8] Ejecutando migraciones de base de datos...
php artisan migrate --force --no-interaction
if errorlevel 1 (
    echo ❌ ERROR: Fallo en las migraciones
    echo.
    echo 💡 TIP: Verifica que database\database.sqlite existe
    pause
    exit /b 1
)
echo ✓ Migraciones ejecutadas exitosamente
echo.

REM [8/8] Compilar assets
echo [8/8] Compilando assets frontend...
echo    (Esto puede tardar 1-2 minutos)
call npm run build
if errorlevel 1 (
    echo ❌ ERROR: Fallo la compilación de assets
    echo.
    pause
    exit /b 1
)
echo ✓ Assets compilados
echo.

REM Guardar info del POS
echo %POS_NAME%> .pos-info
echo Instalado el %date% a las %time%>> .pos-info

REM Crear acceso directo de inicio (opcional)
if not exist "iniciar-%POS_NAME%.bat" (
    echo @echo off> "iniciar-%POS_NAME%.bat"
    echo title POS - %POS_NAME%>> "iniciar-%POS_NAME%.bat"
    echo cd /d "%%~dp0">> "iniciar-%POS_NAME%.bat"
    echo php artisan serve>> "iniciar-%POS_NAME%.bat"
    echo ✓ Script de inicio personalizado creado
)

echo.
echo ╔════════════════════════════════════════════════════╗
echo ║   ✅ INSTALACIÓN COMPLETADA EXITOSAMENTE           ║
echo ╚════════════════════════════════════════════════════╝
echo.
echo 📊 INFORMACIÓN DEL POS INSTALADO:
echo ────────────────────────────────────────────────────
echo    • Nombre: %POS_NAME%
echo    • PHP: %PHP_VERSION%
echo    • Node.js: %NODE_VERSION%
echo    • Base de datos: SQLite (database\database.sqlite)
echo    • Directorio: %CD%
echo.
echo 🚀 SIGUIENTES PASOS:
echo ────────────────────────────────────────────────────
echo.
echo    1. Iniciar el servidor:
echo       • Ejecuta: iniciar.bat
echo       • O ejecuta: iniciar-%POS_NAME%.bat
echo.
echo    2. Configurar el POS:
echo       • Abre: http://localhost:8000/configuracion
echo       • Necesitarás:
echo         - URL del Manager: http://[IP-SERVIDOR]:8000/api
echo         - ID del POS: [número único, ej: 1, 2, 3...]
echo         - Secret: [clave secreta del POS]
echo.
echo    3. Sincronizar catálogo:
echo       • Click en "Sincronizar Catálogo Inicial"
echo       • Espera a que descargue productos y precios
echo.
echo    4. ¡Comenzar a vender! 🛒
echo.
echo 💡 TIPS:
echo ────────────────────────────────────────────────────
echo    • Cada POS debe tener un ID único
echo    • Guarda el secret en un lugar seguro
echo    • Sincroniza regularmente con el Manager
echo    • Haz backup de database\database.sqlite
echo.
echo 📚 DOCUMENTACIÓN:
echo ────────────────────────────────────────────────────
echo    • Ver: INSTALACION_MULTIPLE_POS.md
echo    • Verificar instalación: php verificar-instalacion.php
echo    • Ayuda: SOLUCION_INSTALACION.md
echo.
echo ════════════════════════════════════════════════════
pause
