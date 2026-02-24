# 🛒 POS System - Punto de Venta

Sistema de Punto de Venta (POS) profesional construido con **Laravel 12**, **Livewire 4**, **NativePHP** y **SQLite** para funcionar como **aplicación de escritorio nativa** offline-first.

## 🚀 Características

- 🖥️ **Aplicación de Escritorio Nativa**: Ventana nativa con NativePHP/Electron (no requiere navegador)
- ✅ **Offline First**: Funciona completamente sin conexión a internet
- 🔄 **Sincronización Bidireccional**: Sincroniza productos, precios, stock y ventas con el Manager
- 💳 **Gestión de Ventas**: Carrito de compras intuitivo con búsqueda rápida
- 📊 **Multi-Lista de Precios**: Soporte para diferentes listas de precios por sucursal
- 🎨 **UI Moderna**: Interfaz oscura profesional con Tailwind CSS v4
- 🔍 **Búsqueda Ultrarrápida**: FTS5 (Full Text Search) integrado en SQLite
- 📱 **Responsive**: Diseñado para pantallas táctiles y escritorio
- 🖨️ **Código de Barras**: Soporte para escaneo de códigos de barras
- 🔐 **Multi-POS**: Instalable en múltiples computadoras, cada una con su ID único

## 📋 Requisitos Previos

### Para Desarrollo:
- PHP 8.2 o superior
- Composer
- Node.js 18.x o superior
- NPM
- Extensión SQLite habilitada en PHP

### Para Usuario Final (POS Compilado):
- **¡Solo Windows!** (7/8/10/11)
- **No requiere instalaciones adicionales** (todo incluido en el .exe)

## 🔧 Instalación

### Opción A: Instalación Rápida con Scripts (RECOMENDADO)

**Para desarrolladores** que quieren probar/desarrollar:

1. Doble click en `instalar-pos.bat`
2. Espera 5 minutos
3. Doble click en `iniciar.bat`
4. Abre http://localhost:8000/configuracion

**Para distribuir a múltiples cajas**:

1. Instala NativePHP: `composer require nativephp/electron`
2. Compila: Doble click en `compilar.bat`
3. Distribuye el `.exe` generado en `dist/`

### Opción B: Instalación Manual

```bash
# 1. Instalar dependencias
composer install
npm install

# 2. Configurar
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite

# 3. Migrar base de datos
php artisan migrate

# 4. Compilar assets
npm run build

# 5a. Iniciar en modo desarrollo web
php artisan serve

# 5b. O iniciar en modo desarrollo nativo
php artisan native:serve
```

### 4. Compilar Assets

```bash
# Desarrollo
npm run dev

# Producción
npm run build
```

### 5. Instalar NativePHP (Opcional)

Para empaquetar como aplicación de escritorio:

```bash
composer require nativephp/electron
php artisan native:install electron
```

## 🎯 Configuración Inicial

### Primera Vez

1. Ejecutar el servidor de desarrollo:
```bash
php artisan serve
```

2. Acceder a `http://localhost:8000/configuracion`

3. Completar el formulario con:
   - **URL del Manager**: `http://localhost:8000/api` (URL del manager)
   - **ID del Punto de Venta**: El ID asignado en el manager
   - **Clave Secreta**: La clave secreta del POS

4. Hacer clic en "Conectar y Configurar"

5. Una vez conectado, hacer clic en "Sincronizar Catálogo Inicial"

6. ¡Listo! El POS está configurado y sincronizado

## 📱 Uso del POS

### Realizar una Venta

1. **Buscar Productos**:
   - Escribir el nombre, código interno o código de barras
   - Escanear código de barras con lector
   - Seleccionar de los resultados

2. **Gestionar Carrito**:
   - Incrementar/Decrementar cantidades con botones +/-
   - Eliminar items con el botón de papelera
   - Ver totales en tiempo real

3. **Finalizar Venta**:
   - (Opcional) Ingresar datos del cliente
   - Seleccionar método de pago
   - Hacer clic en "Finalizar Venta"

4. **Sincronizar**:
   - Las ventas se guardan localmente
   - Sincronizar manualmente desde el menú superior
   - O esperar la sincronización automática programada

### Sincronización

#### Sincronización Manual
- Click en el menú (⋮) en la esquina superior derecha
- Seleccionar "🔄 Sincronizar"

#### Sincronización Automática
La sincronización automática puede configurarse mediante tareas programadas:

```bash
php artisan schedule:work
```

## 🏗️ Arquitectura

### Estructura de Base de Datos SQLite

```
📊 configuracion      → Configuración del POS
📦 productos          → Catálogo de productos sincronizado
💰 listas_precios     → Listas de precios de la sucursal
💵 precios            → Precios específicos por producto
🛒 ventas             → Ventas realizadas
📋 detalle_ventas     → Items de cada venta
📈 movimientos_stock  → Movimientos de inventario
```

### Servicios Principales

- **ManagerApiService**: Cliente HTTP para comunicación con el Manager
- **SyncService**: Lógica de sincronización bidireccional

### Componentes Livewire

- **Pos\Venta**: Pantalla principal de punto de venta
- **Configuracion\Inicial**: Configuración y sincronización inicial

## 🔄 Flujo de Sincronización

### Pull (Desde Manager → POS)
1. **Productos**: Catálogo completo + delta sync
2. **Precios**: Listas de precios de la sucursal
3. **Stock**: Stock actual por producto

### Push (Desde POS → Manager)
1. **Ventas**: Ventas pendientes de sincronizar
2. **Movimientos**: Ajustes de stock realizados localmente

## 🎨 Personalización

### Temas y Colores

Los colores principales están en `tailwind.config.js`:

```javascript
theme: {
  extend: {
    colors: {
      // Personalizar colores aquí
    }
  }
}
```

### Configuración de Búsqueda

La búsqueda FTS5 está configurada en la migración `create_productos_table.php`.

## 🐛 Troubleshooting

### Error de Conexión con el Manager

1. Verificar que el Manager esté corriendo
2. Verificar la URL en `.env` o en configuración
3. Revisar logs en `storage/logs/laravel.log`

### Problemas con SQLite

1. Verificar que el archivo `database.sqlite` existe
2. Verificar permisos de escritura en el directorio `database`
3. Verificar que la extensión SQLite está habilitada en PHP

### Sincronización Fallida

1. Verificar conexión a internet
2. Verificar token de autenticación válido
3. Revisar logs del Manager y del POS

## 📚 Recursos

- [Documentación de Laravel](https://laravel.com/docs)
- [Documentación de Livewire](https://livewire.laravel.com)
- [Documentación de Tailwind CSS](https://tailwindcss.com)
- [NativePHP](https://nativephp.com)

## 🤝 Contribuciones

Este es un sistema propietario. Para contribuciones contactar al equipo de desarrollo.

## 📄 Licencia

Todos los derechos reservados © 2026
