# 📦 Guía de Instalación del POS

Esta guía te ayudará a instalar y configurar el sistema POS paso a paso.

## ✅ Prerrequisitos

Antes de comenzar, asegúrate de tener instalado:

- [ ] PHP 8.2 o superior con las siguientes extensiones:
  - `pdo_sqlite`
  - `openssl`
  - `mbstring`
  - `curl`
- [ ] Composer (administrador de dependencias de PHP)
- [ ] Node.js 18.x o superior
- [ ] NPM (incluido con Node.js)

### Verificar Versiones

```bash
php -v          # Debe mostrar PHP 8.2+
composer -V     # Debe mostrar Composer 2.x
node -v         # Debe mostrar Node 18.x+
npm -v          # Debe mostrar NPM 9.x+
```

## 🚀 Instalación Paso a Paso

### Paso 1: Clonar/Copiar el Proyecto

Si ya tienes el proyecto en `C:\MisLaravel\pos`, continúa al paso 2.

### Paso 2: Instalar Dependencias de PHP

Abre una terminal en `C:\MisLaravel\pos` y ejecuta:

```bash
composer install
```

Esto instalará todos los paquetes de PHP necesarios (Laravel, Livewire, etc.).

**Tiempo estimado**: 2-5 minutos

### Paso 3: Instalar Dependencias de JavaScript

En la misma terminal, ejecuta:

```bash
npm install
```

Esto instalará Tailwind CSS y otras dependencias de frontend.

**Tiempo estimado**: 2-3 minutos

### Paso 4: Configurar Variables de Entorno

1. Copia el archivo de ejemplo:
```bash
copy .env.example .env
```

2. Genera la clave de aplicación:
```bash
php artisan key:generate
```

3. Edita el archivo `.env` y configura la conexión con el Manager:
```env
MANAGER_API_URL=http://192.168.1.100:8000/api
```
*Reemplaza con la IP/URL real de tu servidor Manager*

### Paso 5: Crear Base de Datos SQLite

```bash
# Windows
type nul > database\database.sqlite

# Linux/Mac
touch database/database.sqlite
```

### Paso 6: Ejecutar Migraciones

```bash
php artisan migrate
```

Esto creará todas las tablas necesarias en la base de datos SQLite.

**Deberías ver**:
```
✓ 2026_02_24_000001_create_configuracion_table
✓ 2026_02_24_000002_create_productos_table
✓ 2026_02_24_000003_create_listas_precios_table
✓ 2026_02_24_000004_create_precios_table
✓ 2026_02_24_000005_create_ventas_table
✓ 2026_02_24_000006_create_detalle_ventas_table
✓ 2026_02_24_000007_create_movimientos_stock_table
```

### Paso 7: Compilar Assets de Frontend

```bash
npm run build
```

Esto compilará Tailwind CSS y JavaScript para producción.

**Tiempo estimado**: 30-60 segundos

### Paso 8: Iniciar el Servidor

```bash
php artisan serve
```

El servidor iniciará en `http://localhost:8000`

## 🎯 Configuración Inicial del POS

### Paso 9: Acceder a la Configuración

1. Abre tu navegador y ve a: `http://localhost:8000/configuracion`

2. Completa el formulario:

**URL del Servidor Manager**
```
http://192.168.1.100:8000/api
```
*La URL completa de tu servidor Manager con `/api` al final*

**ID del Punto de Venta**
```
1
```
*El ID que te asignaron en el Manager (generalmente 1, 2, 3, etc.)*

**Clave Secreta**
```
tu-clave-secreta-aqui
```
*La clave secreta generada para este POS en el Manager*

3. Haz clic en **"🔌 Conectar y Configurar"**

### Paso 10: Sincronización Inicial

Una vez conectado:

1. Haz clic en **"🔄 Sincronizar Catálogo Inicial"**

2. Espera a que se complete la sincronización (puede tardar según la cantidad de productos)

3. Verás un mensaje de éxito con el resumen:
   - X productos sincronizados
   - X precios sincronizados
   - X registros de stock sincronizados

4. Serás redirigido automáticamente al POS

## ✅ Verificación de la Instalación

Para verificar que todo funciona correctamente:

- [ ] El POS abre en `http://localhost:8000`
- [ ] Puedes buscar productos
- [ ] Los productos aparecen en los resultados de búsqueda
- [ ] Puedes agregar productos al carrito
- [ ] Puedes finalizar una venta
- [ ] El header muestra el nombre del POS y la sucursal

## 🔧 Solución de Problemas Comunes

### Error: "No se pudo conectar con el servidor"

**Causa**: La URL del Manager es incorrecta o el servidor no está accesible.

**Solución**:
1. Verifica que el Manager esté corriendo
2. Prueba hacer ping a la IP del Manager
3. Verifica que la URL incluya `/api` al final
4. Revisa el firewall del servidor Manager

### Error: "Credenciales incorrectas"

**Causa**: El ID del POS o la clave secreta son incorrectos.

**Solución**:
1. Verifica el ID del POS en el Manager
2. Regenera la clave secreta en el Manager si es necesario
3. Asegúrate de copiar la clave completa sin espacios

### Error: "database.sqlite" no existe

**Causa**: El archivo de base de datos no fue creado.

**Solución**:
```bash
# Windows
type nul > database\database.sqlite

# Linux/Mac
touch database/database.sqlite

# Luego ejecutar las migraciones nuevamente
php artisan migrate
```

### Error: "Class 'Livewire' not found"

**Causa**: Las dependencias no se instalaron correctamente.

**Solución**:
```bash
composer install
composer dump-autoload
```

### La UI no se ve bien / no hay estilos

**Causa**: Los assets de frontend no se compilaron.

**Solución**:
```bash
npm install
npm run build
```

## 📱 Empaquetar como Aplicación de Escritorio (Opcional)

Si deseas empaquetar el POS como una aplicación de escritorio standalone:

### Instalar NativePHP

```bash
composer require nativephp/electron
php artisan native:install electron
```

### Compilar la Aplicación

```bash
php artisan native:build electron
```

La aplicación empaquetada estará en `dist/`.

## 🔄 Actualizar el POS

Para actualizar el POS a una nueva versión:

```bash
# Actualizar código (git pull o copiar archivos nuevos)

# Actualizar dependencias
composer install
npm install

# Ejecutar nuevas migraciones
php artisan migrate

# Recompilar assets
npm run build

# Limpiar caché
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

## 📞 Soporte

Si tienes problemas durante la instalación:

1. Revisa los logs en `storage/logs/laravel.log`
2. Contacta al equipo de soporte técnico
3. Proporciona los siguientes datos:
   - Versión de PHP
   - Sistema operativo
   - Mensaje de error completo
   - Logs relevantes

## 🎉 ¡Listo!

Tu POS está configurado y listo para usarse. ¡Felices ventas! 🛒
