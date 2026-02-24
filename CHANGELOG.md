# Changelog

Todos los cambios notables del proyecto POS serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto adhiere a [Semantic Versioning](https://semver.org/lang/es/).

## [1.0.0] - 2026-02-24

### 🎉 Lanzamiento Inicial

#### Añadido

**Core System**
- Sistema POS completo con Laravel 12 y Livewire 4
- Base de datos SQLite para funcionamiento offline
- Arquitectura offline-first con sincronización bidireccional
- Autenticación con Manager vía Sanctum tokens

**Sincronización**
- `SyncService`: Servicio de sincronización bidireccional
- `ManagerApiService`: Cliente HTTP para comunicación con Manager API
- Sincronización de productos con soporte delta sync
- Sincronización de precios y listas de precios por sucursal
- Sincronización de stock en tiempo real
- Push de ventas realizadas offline
- Push de movimientos de stock
- Comando artisan: `php artisan pos:sync`

**Gestión de Productos**
- Catálogo de productos sincronizado desde Manager
- Búsqueda FTS5 (Full Text Search) ultrarrápida en SQLite
- Búsqueda por nombre, código interno y código de barras
- Soporte para productos simples y configurables (variantes)
- Gestión de stock local
- Soporte para múltiples listas de precios
- Cálculo automático de precios efectivos

**Punto de Venta**
- Interfaz de venta moderna con Livewire
- Carrito de compras reactivo
- Búsqueda en tiempo real con debounce
- Selección automática de producto único
- Modal de resultados múltiples
- Incremento/decremento de cantidades
- Validación de stock disponible
- Múltiples métodos de pago (efectivo, tarjeta, transferencia)
- Captura opcional de datos de cliente
- Generación automática de número de venta
- Registro automático de movimientos de stock

**Configuración**
- Pantalla de configuración inicial amigable
- Wizard de conexión con Manager
- Sincronización inicial automática
- Guardado seguro de credenciales
- Sistema de configuración key-value en SQLite

**UI/UX**
- Diseño moderno dark mode con Tailwind CSS v4
- Interfaz responsive optimizada para escritorio
- Header con información del POS y sucursal
- Reloj en tiempo real
- Indicador de estado de conexión
- Notificaciones toast para feedback
- Animaciones suaves con Alpine.js
- Soporte para pantallas táctiles

**Seguridad**
- Autenticación mediante tokens Sanctum
- Middleware de verificación de configuración
- Validación de datos en formularios
- Transacciones de base de datos para ventas
- Ocultamiento de claves secretas

**Developer Experience**
- Código limpio y bien estructurado
- Comentarios PHPDoc en todos los métodos
- Migraciones de base de datos completas
- Seeders de configuración inicial
- Scripts de verificación de instalación
- Documentación completa (README, INSTALACION, RESUMEN_PROYECTO)
- Archivo CHANGELOG para seguimiento de versiones

#### Modelos Eloquent

- `Configuracion`: Gestión de configuración key-value
- `Producto`: Catálogo de productos
- `ListaPrecio`: Listas de precios
- `Precio`: Precios específicos por producto
- `Venta`: Ventas realizadas
- `DetalleVenta`: Items de cada venta
- `MovimientoStock`: Movimientos de inventario

#### Migraciones SQLite

1. `create_configuracion_table`: Tabla de configuración
2. `create_productos_table`: Catálogo + índice FTS5
3. `create_listas_precios_table`: Listas de precios
4. `create_precios_table`: Precios específicos
5. `create_ventas_table`: Ventas
6. `create_detalle_ventas_table`: Detalles de ventas
7. `create_movimientos_stock_table`: Movimientos de stock

#### Componentes Livewire

- `Pos\Venta`: Pantalla principal de punto de venta
- `Configuracion\Inicial`: Configuración y setup inicial

#### Servicios

- `ManagerApiService`: Cliente API con reintentos y timeout
- `SyncService`: Lógica completa de sincronización bidireccional

#### Comandos Artisan

- `pos:sync`: Sincronización manual desde consola
  - `--pull`: Solo sincronización desde Manager
  - `--push`: Solo envío de datos al Manager

#### API Endpoints Consumidos

- `POST /api/v1/pos/auth`: Autenticación de POS
- `GET /api/v1/sync/productos`: Sincronización de productos
- `GET /api/v1/sync/precios`: Sincronización de precios
- `GET /api/v1/sync/stock`: Sincronización de stock
- `POST /api/v1/sync/ventas`: Envío de ventas
- `POST /api/v1/sync/movimientos`: Envío de movimientos
- `GET /api/v1/precios/{id}`: Obtener precio de producto

#### Scripts y Utilidades

- `verificar-instalacion.php`: Script de verificación de requisitos
- Soporte para escaneo de código de barras
- Helpers JavaScript para formato de moneda y fechas

#### Documentación

- `README.md`: Documentación principal del proyecto
- `INSTALACION.md`: Guía detallada de instalación
- `RESUMEN_PROYECTO.md`: Arquitectura y detalles técnicos
- `CHANGELOG.md`: Este archivo de cambios

### Notas Técnicas

**Requisitos del Sistema:**
- PHP 8.2+
- SQLite 3.x
- Node.js 18.x+
- Extensiones PHP: pdo_sqlite, openssl, mbstring, curl

**Dependencias Principales:**
- Laravel Framework 12.x
- Livewire 4.x
- Tailwind CSS 4.x
- Alpine.js 3.x
- Guzzle HTTP 7.x

**Base de Datos:**
- SQLite para almacenamiento local
- FTS5 para búsqueda de texto completo
- Triggers automáticos para mantener índices FTS

**Performance:**
- Índices optimizados en campos de búsqueda
- Eager loading de relaciones
- Debounce en búsqueda (300ms)
- Assets compilados y minificados

---

## [Unreleased]

### Planeado para futuras versiones

- Sincronización automática programada
- Impresión de tickets de venta
- Reportes de ventas locales
- Gestión de caja (apertura/cierre)
- Modo offline completo con queue
- Backup automático de la base de datos
- Dashboard de estadísticas
- Gestión de devoluciones
- Soporte multi-moneda
- Empaquetado con NativePHP/Electron

---

[1.0.0]: https://github.com/tu-repo/pos/releases/tag/v1.0.0
