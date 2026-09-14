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

REM [1/11] Verificar PHP
echo [1/11] Verificando PHP...
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

REM [2/11] Verificar Composer
echo [2/11] Verificando Composer...
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

REM [3/11] Instalar dependencias PHP
echo [3/11] Instalando dependencias PHP...
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

REM [4/11] Verificar Node.js
echo [4/11] Verificando Node.js...
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

REM [5/11] Instalar dependencias JavaScript
echo [5/11] Instalando dependencias JavaScript...
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

REM [6/11] Configurar entorno
echo [6/11] Configurando entorno...

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

REM [7/11] Ejecutar migraciones
echo [7/11] Ejecutando migraciones de base de datos...
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

REM [8/11] Compilar assets
echo [8/11] Compilando assets frontend...
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

REM [9/11] Vincular con el Manager
echo [9/11] Vinculando esta caja con el Manager...
echo.
echo    Un usuario del Manager genera el codigo en:
echo    Puntos de venta ^> boton "Codigo"
echo.
set /p CODIGO_INSTALACION="Codigo de instalacion (Enter para configurar despues): "

if "%CODIGO_INSTALACION%"=="" (
    echo ⚠ Sin codigo: la caja queda sin vincular.
    echo    Podes hacerlo despues con: php artisan pos:provision
) else (
    php artisan pos:provision "%CODIGO_INSTALACION%"
    if errorlevel 1 (
        echo.
        echo ⚠ No se pudo vincular. El POS quedo instalado igual.
        echo    Pedi un codigo nuevo y ejecuta: php artisan pos:provision
    )
)
echo.

REM [10/11] Sincronizacion automatica
REM Sin esto la caja guarda las ventas localmente pero nunca las manda al Manager,
REM y el stock se queda viejo. Son tareas de Windows, arrancan al iniciar sesion.
echo [10/11] Configurando sincronizacion automatica...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar-inicio-automatico.ps1" -Carpeta "%~dp0." -Nombre "%POS_NAME%"
if errorlevel 1 (
    echo ⚠ No se pudo registrar el inicio automatico.
    echo    El POS funciona igual, pero hay que levantar la sincronizacion a mano
    echo    ejecutando servicios.bat cada vez.
) else (
    echo ✓ Sincronizacion automatica configurada
)
echo.

REM [11/11] Acceso directo en el escritorio
REM Abre la caja en modo aplicacion (sin pestañas ni barra de direcciones).
echo [11/11] Creando acceso directo en el escritorio...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0crear-acceso-directo.ps1" -Carpeta "%~dp0." -Nombre "%POS_NAME%"
if errorlevel 1 (
    echo ⚠ No se pudo crear el acceso directo.
    echo    Podes crearlo despues con: crear-acceso-directo.bat
) else (
    echo ✓ Acceso directo creado
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
echo    2. Si NO ingresaste el código de instalación:
echo       • Pedí uno en el Manager (Puntos de venta ^> Instalar)
echo       • Ejecuta: php artisan pos:provision
echo.
echo    3. ¡Comenzar a vender! 🛒
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
