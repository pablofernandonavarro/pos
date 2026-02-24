# 📊 Resumen del Proyecto POS

## 🎯 Descripción General

Sistema de Punto de Venta (POS) profesional construido con Laravel 12, Livewire 4, Tailwind CSS v4 y SQLite. Diseñado para funcionar **offline-first** con sincronización bidireccional con un servidor Manager centralizado.

## 🏗️ Arquitectura del Sistema

### Tecnologías Utilizadas

| Componente | Tecnología | Versión |
|------------|-----------|---------|
| Framework Backend | Laravel | 12.x |
| Base de Datos | SQLite | 3.x |
| Framework Frontend | Livewire | 4.x |
| CSS Framework | Tailwind CSS | 4.x |
| JavaScript | Alpine.js | 3.x |
| Empaquetado | NativePHP | (Opcional) |

### Estructura de Directorios

```
pos/
├── app/
│   ├── Livewire/
│   │   ├── Configuracion/
│   │   │   └── Inicial.php                  # Configuración inicial del POS
│   │   └── Pos/
│   │       └── Venta.php                     # Pantalla principal de ventas
│   ├── Models/
│   │   ├── Configuracion.php                 # Configuración key-value
│   │   ├── Producto.php                      # Modelo de productos
│   │   ├── ListaPrecio.php                   # Listas de precios
│   │   ├── Precio.php                        # Precios específicos
│   │   ├── Venta.php                         # Ventas realizadas
│   │   ├── DetalleVenta.php                  # Items de ventas
│   │   └── MovimientoStock.php               # Movimientos de inventario
│   └── Services/
│       ├── ManagerApiService.php             # Cliente API del Manager
│       └── SyncService.php                   # Lógica de sincronización
├── database/
│   ├── migrations/                           # Migraciones SQLite
│   │   ├── 2026_02_24_000001_create_configuracion_table.php
│   │   ├── 2026_02_24_000002_create_productos_table.php
│   │   ├── 2026_02_24_000003_create_listas_precios_table.php
│   │   ├── 2026_02_24_000004_create_precios_table.php
│   │   ├── 2026_02_24_000005_create_ventas_table.php
│   │   ├── 2026_02_24_000006_create_detalle_ventas_table.php
│   │   └── 2026_02_24_000007_create_movimientos_stock_table.php
│   └── database.sqlite                       # Base de datos SQLite
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   ├── pos.blade.php                 # Layout principal del POS
│   │   │   └── guest.blade.php               # Layout para páginas públicas
│   │   └── livewire/
│   │       ├── configuracion/
│   │       │   └── inicial.blade.php
│   │       └── pos/
│   │           └── venta.blade.php
│   ├── css/
│   │   └── app.css                           # Estilos Tailwind
│   └── js/
│       └── app.js                            # JavaScript personalizado
├── routes/
│   └── web.php                               # Rutas de la aplicación
├── .env.example                              # Ejemplo de configuración
├── README.md                                 # Documentación principal
├── INSTALACION.md                            # Guía de instalación
├── composer.json                             # Dependencias PHP
├── package.json                              # Dependencias JavaScript
├── tailwind.config.js                        # Configuración Tailwind
└── vite.config.js                            # Configuración Vite

```

## 🔄 Flujo de Sincronización

### 1. Sincronización Pull (Manager → POS)

```mermaid
Manager API → POS Local
  ├─ Productos (catálogo completo + delta sync)
  ├─ Listas de Precios (de la sucursal)
  ├─ Precios Específicos (overrides)
  └─ Stock (cantidad por producto)
```

**Endpoints utilizados:**
- `GET /api/v1/sync/productos?updated_since=...`
- `GET /api/v1/sync/precios`
- `GET /api/v1/sync/stock`

### 2. Sincronización Push (POS → Manager)

```mermaid
POS Local → Manager API
  ├─ Ventas Realizadas
  └─ Movimientos de Stock
```

**Endpoints utilizados:**
- `POST /api/v1/sync/ventas`
- `POST /api/v1/sync/movimientos`

### 3. Autenticación

```
POS → POST /api/v1/pos/auth
  Request: { punto_de_venta_id, secret }
  Response: { token, sucursal_id, sucursal_nombre, pdv_nombre }
```

El token se guarda en la tabla `configuracion` y se usa en todas las peticiones posteriores.

## 📊 Esquema de Base de Datos

### Tabla: configuracion
Almacena configuración key-value del POS.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INTEGER | ID autoincremental |
| clave | VARCHAR | Clave única (manager_api_url, access_token, etc.) |
| valor | TEXT | Valor de la configuración |

**Claves importantes:**
- `manager_api_url`: URL del servidor Manager
- `access_token`: Token de autenticación Sanctum
- `punto_de_venta_id`: ID del POS
- `sucursal_id`: ID de la sucursal asignada
- `configurado`: Booleano indicando si está configurado

### Tabla: productos
Catálogo de productos sincronizado desde el Manager.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INTEGER | ID del producto (mismo que en Manager) |
| nombre | VARCHAR | Nombre del producto |
| codigo_interno | VARCHAR | Código interno |
| codigo_barras | VARCHAR | Código de barras |
| busqueda | TEXT | Campo de búsqueda (indexado FTS5) |
| precio | DECIMAL | Precio base |
| stock | INTEGER | Stock actual |
| product_type | VARCHAR | simple/configurable |
| parent_id | INTEGER | ID del padre (para variantes) |
| es_vendible | BOOLEAN | Si está disponible para venta |

**Índices:**
- FTS5 en campo `busqueda` para búsqueda rápida
- Índice en `codigo_interno` y `codigo_barras`
- Índice en `es_vendible`

### Tabla: ventas
Ventas realizadas en el POS.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INTEGER | ID autoincremental |
| lista_precio_id | INTEGER | Lista de precios usada |
| numero_venta | VARCHAR | Número de venta (ej: PDV01-000123) |
| fecha | TIMESTAMP | Fecha y hora de la venta |
| subtotal | DECIMAL | Subtotal antes de descuentos |
| descuento | DECIMAL | Descuento aplicado |
| total | DECIMAL | Total final |
| sincronizado | BOOLEAN | Si fue sincronizada con Manager |
| metodo_pago | VARCHAR | efectivo/tarjeta/transferencia |

### Tabla: detalle_ventas
Items de cada venta.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INTEGER | ID autoincremental |
| venta_id | INTEGER | FK a ventas |
| product_id | INTEGER | FK a productos |
| cantidad | INTEGER | Cantidad vendida |
| precio_unitario | DECIMAL | Precio al momento de venta |
| subtotal | DECIMAL | cantidad × precio_unitario |

## 🎨 Características de la UI

### Paleta de Colores

- **Background Principal**: `bg-slate-900` (#0f172a)
- **Background Secundario**: `bg-slate-800` (#1e293b)
- **Bordes**: `border-slate-700` (#334155)
- **Texto Principal**: `text-white`
- **Texto Secundario**: `text-slate-400`
- **Acento Primario**: `bg-blue-600` (#2563eb)
- **Acento Éxito**: `bg-green-500` (#22c55e)
- **Acento Error**: `bg-red-500` (#ef4444)

### Componentes Principales

1. **Pantalla de Ventas** (`/`)
   - Búsqueda en tiempo real con FTS5
   - Carrito de compras lateral
   - Incremento/decremento de cantidades
   - Múltiples métodos de pago
   - Datos opcionales del cliente

2. **Configuración Inicial** (`/configuracion`)
   - Formulario de conexión con Manager
   - Sincronización inicial de catálogo
   - Validación de credenciales

3. **Header Global**
   - Nombre del POS y sucursal
   - Reloj en tiempo real
   - Indicador de conexión
   - Menú de opciones (sincronizar, configuración, salir)

### Responsive Design

- Optimizado para pantallas de escritorio (1280px+)
- Panel de carrito fijo de 480px
- Diseño táctil friendly (botones grandes)

## 🔍 Búsqueda de Productos

### Características

- **FTS5 (Full Text Search)**: Búsqueda ultrarrápida en SQLite
- **Búsqueda múltiple**: Por nombre, código interno, código de barras
- **Resultados instantáneos**: Debounce de 300ms
- **Selección rápida**: Si hay 1 resultado, se agrega automáticamente
- **Modal de resultados**: Si hay múltiples coincidencias

### Implementación

```sql
-- Tabla virtual FTS5
CREATE VIRTUAL TABLE productos_fts USING fts5(id, busqueda);

-- Triggers para mantener sincronizado
CREATE TRIGGER productos_ai AFTER INSERT ...
CREATE TRIGGER productos_au AFTER UPDATE ...
CREATE TRIGGER productos_ad AFTER DELETE ...
```

## 🔐 Seguridad

### Autenticación

- Token Sanctum guardado localmente
- Token incluido en todas las peticiones API
- Middleware para verificar configuración

### Validación

- Validación de stock antes de agregar al carrito
- Validación de precios en el momento de la venta
- Transacciones de base de datos para ventas

## 🚀 Comandos Útiles

### Desarrollo

```bash
# Iniciar servidor de desarrollo
php artisan serve

# Compilar assets en modo watch
npm run dev

# Ver logs en tiempo real
tail -f storage/logs/laravel.log
```

### Producción

```bash
# Compilar assets optimizados
npm run build

# Limpiar caché
php artisan optimize:clear

# Ejecutar migraciones
php artisan migrate --force
```

### Sincronización

```bash
# Sincronización manual via consola
php artisan tinker
>>> $sync = app(\App\Services\SyncService::class);
>>> $sync->syncBidireccional();
```

## 📈 Performance

### Optimizaciones Implementadas

1. **Índices de Base de Datos**
   - FTS5 para búsqueda de texto completo
   - Índices en campos de búsqueda frecuente
   - Índices en foreign keys

2. **Lazy Loading**
   - Eager loading de relaciones en servicios
   - Paginación donde sea necesario

3. **Caché**
   - Configuración cacheada en memoria durante la sesión
   - Lista de precios default cacheada

4. **Frontend**
   - Debounce en búsqueda (300ms)
   - Assets compilados y minificados
   - Alpine.js para interactividad reactiva

## 📦 Dependencias Principales

### PHP (composer.json)

```json
{
  "laravel/framework": "^12.0",
  "laravel/sanctum": "^4.0",
  "livewire/livewire": "^4.0",
  "guzzlehttp/guzzle": "^7.2"
}
```

### JavaScript (package.json)

```json
{
  "tailwindcss": "^4.0.0",
  "alpinejs": "^3.13.3",
  "vite": "^5.0"
}
```

## 🐛 Debug

### Logs Importantes

- `storage/logs/laravel.log`: Logs generales
- Errores de API se loguean con contexto completo
- Errores de sincronización incluyen traza completa

### Verificar Estado del POS

```php
// En tinker
\App\Models\Configuracion::all();
\App\Models\Configuracion::isConfigured();
\App\Models\Producto::count();
\App\Models\Venta::pendientes()->count();
```

## 🎯 Próximas Mejoras (Backlog)

- [ ] Sincronización automática programada
- [ ] Impresión de tickets de venta
- [ ] Reportes de ventas locales
- [ ] Gestión de caja (apertura/cierre)
- [ ] Modo offline completo con queue
- [ ] Backup automático de la base de datos
- [ ] Soporte multi-moneda
- [ ] Dashboard de estadísticas
- [ ] Gestión de devoluciones

## 📞 Soporte Técnico

Para reportar bugs o solicitar features:
- Revisar logs en `storage/logs/`
- Verificar conectividad con Manager
- Contactar al equipo de desarrollo

---

**Versión**: 1.0.0
**Fecha**: Febrero 2026
**Autor**: Equipo de Desarrollo
