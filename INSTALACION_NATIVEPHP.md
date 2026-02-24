# 🖥️ Instalación con NativePHP - Aplicación de Escritorio

## 🎯 ¿Qué es NativePHP?

NativePHP convierte tu aplicación Laravel en una **aplicación de escritorio nativa** usando Electron. Esto significa:

✅ **Sin necesidad de servidor web** (Apache/Nginx)
✅ **Sin necesidad de navegador** (ventana nativa)
✅ **Ejecutable standalone** (un .exe por POS)
✅ **Base de datos SQLite embebida**
✅ **Más fácil de instalar** en múltiples computadoras
✅ **Más profesional** para el usuario final

## 📦 Estructura con NativePHP

```
Servidor Manager (192.168.1.100)
    ↓ sincroniza via API
├─ POS-Caja-1.exe (Windows standalone)
├─ POS-Caja-2.exe (Windows standalone)
├─ POS-Caja-3.exe (Windows standalone)
└─ POS-Deposito.exe (Windows standalone)
```

## 🚀 Instalación de NativePHP

### Paso 1: Instalar NativePHP en el Proyecto

```bash
cd C:\MisLaravel\pos

# Instalar NativePHP para Electron
composer require nativephp/electron

# Instalar NativePHP
php artisan native:install electron
```

### Paso 2: Publicar Configuración

```bash
php artisan vendor:publish --tag=native-config
```

Esto creará `config/native.php`

### Paso 3: Configurar la Aplicación

Edita `config/native.php`:

```php
return [
    'name' => env('APP_NAME', 'POS System'),
    'version' => '1.0.0',
    'author' => 'Tu Empresa',

    'electron' => [
        'width' => 1280,
        'height' => 900,
        'resizable' => true,
        'fullscreen' => false,
    ],
];
```

## 🔨 Compilar Aplicación para Distribución

### Desarrollo (Testing)

```bash
# Ejecutar en modo desarrollo
php artisan native:serve
```

Esto abrirá la aplicación como una ventana de escritorio.

### Producción (Crear .exe)

```bash
# Compilar aplicación para Windows
php artisan native:build windows
```

Esto creará un instalador en `dist/` que puedes distribuir.

## 📦 Distribución a Múltiples POS

### Método 1: Instalador Único Configurable (RECOMENDADO)

1. **Compila una vez**:
   ```bash
   php artisan native:build windows
   ```

2. **Distribuye el instalador** a cada computadora:
   ```
   dist/POS-System-Setup-1.0.0.exe
   ```

3. **En cada PC**:
   - Ejecuta el instalador
   - La aplicación se instala en `C:\Users\[Usuario]\AppData\Local\POS System`
   - Primera vez que se ejecuta, muestra la pantalla de configuración
   - Cada POS configura su ID único

### Método 2: Builds Personalizados

Puedes crear builds personalizados para cada caja:

```bash
# Build para Caja 1
APP_NAME="POS - Caja 1" php artisan native:build windows

# Build para Caja 2
APP_NAME="POS - Caja 2" php artisan native:build windows
```

## 🎯 Ventajas de NativePHP para Multi-POS

### 1. Instalación Simplificada

**Antes (Laravel tradicional)**:
- Instalar PHP
- Instalar Composer
- Instalar Node.js
- Configurar servidor web
- Configurar base de datos
- Abrir navegador manualmente

**Con NativePHP**:
- Doble click en instalador
- Configurar ID y Secret
- ¡Listo!

### 2. Experiencia de Usuario

- ✅ Ventana nativa de Windows (no navegador)
- ✅ Icono en barra de tareas
- ✅ Notificaciones del sistema
- ✅ Atajos de teclado nativos
- ✅ Menú de aplicación personalizado

### 3. Portabilidad

- ✅ Un solo archivo ejecutable por POS
- ✅ Fácil de actualizar (solo reemplazar .exe)
- ✅ No requiere instalación de dependencias

## 📝 Configuración Específica para POS

### Archivo: `app/Providers/NativeAppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Native\Laravel\Facades\Window;
use Native\Laravel\Menu\Menu;

class NativeAppServiceProvider
{
    public function boot(): void
    {
        // Configurar ventana principal
        Window::open()
            ->title(config('app.name'))
            ->width(1280)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(768)
            ->resizable(true);

        // Menú personalizado
        Menu::new()
            ->appMenu()
            ->submenu('Archivo', [
                Menu::link('Sincronizar', route('pos.sync')),
                Menu::separator(),
                Menu::quit(),
            ])
            ->submenu('Ver', [
                Menu::link('Nueva Venta', route('pos.venta')),
                Menu::link('Configuración', route('pos.configuracion')),
            ])
            ->register();
    }
}
```

## 🔧 Personalización por POS

### Build con Configuración Embebida

Puedes crear un build con configuración pre-cargada:

**build-caja-1.bat**:
```bat
@echo off
echo Compilando POS para Caja 1...

REM Configurar variables
set APP_NAME=POS - Caja 1
set PUNTO_DE_VENTA_ID=1

REM Compilar
php artisan native:build windows

REM Renombrar
move "dist\POS System Setup.exe" "dist\POS-Caja-1-Setup.exe"

echo ✓ Compilado: dist\POS-Caja-1-Setup.exe
pause
```

## 📦 Estructura de la Aplicación Compilada

```
POS System (instalado en PC)
└─ C:\Users\[Usuario]\AppData\Local\POS System\
    ├── POS System.exe          (ejecutable)
    ├── resources\
    │   └── app.asar            (aplicación Laravel)
    └── database\
        └── database.sqlite      (base de datos local)
```

## 🔄 Actualización de Múltiples POS

### Auto-Update (Recomendado)

NativePHP soporta auto-update. Configura en `config/native.php`:

```php
'updater' => [
    'enabled' => true,
    'url' => 'https://tu-servidor.com/updates',
],
```

### Update Manual

1. Compila nueva versión:
   ```bash
   php artisan native:build windows
   ```

2. Distribuye nuevo instalador a cada POS

3. Los usuarios ejecutan el instalador (preserva datos)

## 🎨 Personalización UI

### Splash Screen

Crea `resources/images/splash.png` (1000x600px)

NativePHP lo mostrará al iniciar.

### Icono de Aplicación

Crea `resources/images/icon.png` (512x512px)

Se usará como icono de la aplicación.

### Notificaciones del Sistema

```php
use Native\Laravel\Facades\Notification;

// En tu componente Livewire
public function ventaFinalizada()
{
    Notification::new()
        ->title('Venta Realizada')
        ->message('Venta #'.$this->numeroVenta.' completada')
        ->show();
}
```

## 🚀 Flujo de Instalación Final

### Para el Desarrollador:

1. **Configurar NativePHP**:
   ```bash
   composer require nativephp/electron
   php artisan native:install electron
   ```

2. **Personalizar configuración**:
   - Editar `config/native.php`
   - Agregar iconos en `resources/images/`
   - Configurar menús en `NativeAppServiceProvider`

3. **Compilar**:
   ```bash
   php artisan native:build windows
   ```

4. **Distribuir**:
   - Copiar `dist/POS-System-Setup.exe` a USB o red
   - O publicar en servidor para auto-update

### Para el Usuario Final (Operador de Caja):

1. **Instalar**:
   - Doble click en `POS-System-Setup.exe`
   - Next, Next, Finish

2. **Configurar** (primera vez):
   - Se abre automáticamente
   - Ingresar URL Manager, ID POS, Secret
   - Click "Sincronizar"

3. **Usar**:
   - Doble click en icono de escritorio
   - ¡A vender!

## 💡 Tips para NativePHP Multi-POS

### 1. Identificación Automática

Puedes usar el nombre de la PC para identificar el POS:

```php
// En la configuración inicial
$pcName = gethostname();
$posName = "POS - {$pcName}";
```

### 2. Modo Kiosk (Opcional)

Para evitar que el operador cierre la app:

```php
Window::open()
    ->kiosk(true)  // Pantalla completa sin barra de título
    ->alwaysOnTop(true);
```

### 3. Backup Automático

Programa backups automáticos:

```php
use Native\Laravel\Facades\System;

// Cada 4 horas
System::schedule()->exec('backup.bat')->everyFourHours();
```

## 🆘 Troubleshooting NativePHP

### Build falla

**Solución**: Instala Node.js y npm:
```bash
node -v  # Debe mostrar v18+
npm -v   # Debe mostrar v9+
```

### Aplicación no inicia

**Solución**: Verifica logs en:
```
C:\Users\[Usuario]\AppData\Local\POS System\logs\
```

### Base de datos no se crea

**Solución**: NativePHP usa un path especial:
```php
// En config/database.php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => \Native\Laravel\Facades\Storage::userHome('database.sqlite'),
],
```

## 📊 Comparación

| Aspecto | Laravel Tradicional | NativePHP |
|---------|---------------------|-----------|
| Instalación | Compleja | Simple (doble click) |
| Requisitos | PHP, Composer, Node, Servidor | Solo el .exe |
| Experiencia | Navegador web | Aplicación nativa |
| Distribución | Copiar archivos | Un instalador |
| Updates | Manual complejo | Auto-update |
| UI | Web-like | Nativa |

## ✅ Recomendación Final

Para un sistema POS con **múltiples instalaciones**, NativePHP es **IDEAL** porque:

1. **Fácil de distribuir** (un .exe)
2. **Fácil de instalar** (doble click)
3. **Fácil de actualizar** (auto-update o reemplazar .exe)
4. **Profesional** (ventana nativa, no navegador)
5. **Confiable** (no depende de configuración del navegador)

---

**¿Listo para compilar tu primera aplicación POS con NativePHP?** 🚀
