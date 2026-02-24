# 📘 Guía Completa: Sistema Multi-POS con NativePHP

## 🎯 Resumen Ejecutivo

Este documento explica cómo instalar y distribuir el sistema POS en **múltiples computadoras** usando **NativePHP** para crear aplicaciones de escritorio standalone.

### ¿Qué es este sistema?

Un **Sistema de Punto de Venta** completo que:
- Se instala como **aplicación de escritorio nativa** (no web)
- Funciona **offline** (sin internet)
- Se **sincroniza** con un servidor Manager central
- Cada caja tiene su **propia base de datos SQLite**
- Cada caja tiene un **ID único**

### Arquitectura

```
┌─────────────────────────────────────┐
│   SERVIDOR MANAGER (Laravel)        │
│   Base de datos central (MySQL)     │
│   IP: 192.168.1.100:8000           │
└─────────────────┬───────────────────┘
                  │ API REST
         ┌────────┴────────┐
         │                 │
    ┌────▼─────┐     ┌────▼─────┐
    │  POS 1   │     │  POS 2   │
    │ Caja 1   │     │ Caja 2   │
    │ SQLite   │     │ SQLite   │
    └──────────┘     └──────────┘
```

---

## 📋 Parte 1: Preparación (Una Sola Vez)

### 1.1 Configurar el Proyecto Base

En tu PC de desarrollo:

```bash
cd C:\MisLaravel\pos

# Instalar dependencias
composer install
npm install

# Instalar NativePHP
composer require nativephp/electron
php artisan native:install electron

# Configurar
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate
npm run build
```

### 1.2 Preparar Iconos (Opcional pero Recomendado)

Crea los siguientes archivos en `resources/images/`:

- **icon.png** (512x512px) - Icono principal
- **icon.ico** (para Windows)
- **splash.png** (1000x600px) - Pantalla de inicio

Puedes usar herramientas como:
- https://www.icoconverter.com/ (PNG a ICO)
- https://www.canva.com/ (diseñar iconos)

### 1.3 Registrar POS en el Manager

En el servidor Manager, registra cada POS:

```bash
# En el manager
php artisan tinker

# Crear POS 1
$secret1 = \Illuminate\Support\Str::random(32);
$pos1 = \App\Models\PuntoDeVenta::create([
    'sucursal_id' => 1,
    'nombre' => 'Caja 1',
    'secret' => bcrypt($secret1),
    'activo' => true,
]);
echo "POS ID: {$pos1->id}, Secret: {$secret1}\n";

# Crear POS 2
$secret2 = \Illuminate\Support\Str::random(32);
$pos2 = \App\Models\PuntoDeVenta::create([
    'sucursal_id' => 1,
    'nombre' => 'Caja 2',
    'secret' => bcrypt($secret2),
    'activo' => true,
]);
echo "POS ID: {$pos2->id}, Secret: {$secret2}\n";

# Repetir para cada POS...
```

**⚠️ IMPORTANTE**: Guarda estos secrets en un lugar seguro. Los necesitarás al configurar cada POS.

---

## 📦 Parte 2: Compilación

### Opción A: Build Genérico (UN INSTALADOR PARA TODOS)

**Ventajas**:
- Un solo instalador para todas las cajas
- Se configura en la instalación
- Más flexible

**Cómo hacerlo**:

```bash
# Método 1: Script automático
compilar.bat
# Selecciona opción 1 (build genérico)

# Método 2: Manual
php artisan native:build windows
```

**Resultado**: `dist/POS System Setup.exe` (~150-250 MB)

### Opción B: Builds Específicos (UN INSTALADOR POR CAJA)

**Ventajas**:
- Cada instalador ya viene con el nombre del POS
- Reduce confusión del operador
- Más profesional

**Cómo hacerlo**:

```bash
# Método 1: Script automático (compilar uno por uno)
compilar.bat
# Selecciona opción 2 y completa los datos

# Método 2: Compilar todos de una vez
compilar-todos-los-pos.bat
# Edita el script antes para definir tus POS
```

**Resultado**:
- `dist/POS-Caja-1-Setup.exe`
- `dist/POS-Caja-2-Setup.exe`
- `dist/POS-Caja-3-Setup.exe`
- etc.

---

## 💾 Parte 3: Distribución

### 3.1 Preparar USB de Instalación

1. **Formatea una USB** (8GB+)

2. **Copia los instaladores**:
   ```
   USB:\
   ├── POS-Caja-1-Setup.exe
   ├── POS-Caja-2-Setup.exe
   ├── POS-Caja-3-Setup.exe
   └── INSTRUCCIONES.txt
   ```

3. **Crea INSTRUCCIONES.txt**:
   ```
   INSTALACIÓN POS SYSTEM
   ═══════════════════════════════

   1. Ejecutar el instalador correspondiente
      (Caja 1 → POS-Caja-1-Setup.exe)

   2. Seguir el asistente de instalación

   3. Al abrir por primera vez, configurar:
      - URL Manager: http://192.168.1.100:8000/api
      - ID del POS: (ver tabla abajo)
      - Secret: (ver tabla abajo)

   TABLA DE CONFIGURACIÓN
   ══════════════════════════════════════════════════
   Caja      | ID  | Secret
   ──────────┼─────┼─────────────────────────────────
   Caja 1    | 1   | [secret-caja-1]
   Caja 2    | 2   | [secret-caja-2]
   Caja 3    | 3   | [secret-caja-3]
   Depósito  | 4   | [secret-deposito]
   ══════════════════════════════════════════════════

   ⚠️ IMPORTANTE: Cada caja debe tener un ID único
   ```

### 3.2 Instalación en Cada Computadora

**Por cada POS**:

1. **Insertar USB** en la computadora

2. **Ejecutar el instalador** (doble click)
   - Next, Next, Install
   - Seleccionar ubicación (por defecto está bien)
   - Esperar 1-2 minutos

3. **Abrir la aplicación**
   - Se abre automáticamente al terminar
   - O buscar "POS System" en el menú Inicio

4. **Configurar** (primera vez):
   - URL del Manager: `http://192.168.1.100:8000/api`
   - ID del POS: `1` (o el que corresponda)
   - Secret: `[copiar desde tabla]`
   - Click "Conectar y Configurar"

5. **Sincronizar**:
   - Click "Sincronizar Catálogo Inicial"
   - Esperar 2-5 minutos (según cantidad de productos)

6. **¡Listo!** El POS está configurado

---

## 🔄 Parte 4: Operación Diaria

### Inicio de Jornada

1. **Encender computadora**
2. **Abrir POS** (doble click en icono de escritorio)
3. **Esperar a que cargue** (5-10 segundos)
4. **Opcional**: Sincronizar productos (menú → Sincronizar)

### Durante el Día

- **Realizar ventas** normalmente
- Las ventas se guardan **localmente** (offline)
- **No requiere conexión** a internet

### Fin de Jornada

1. **Sincronizar ventas**:
   - Menú (⋮) → Sincronizar
   - Esperar a que termine

2. **Cerrar aplicación**:
   - Archivo → Salir

3. **Apagar computadora** (opcional)

---

## 🛠️ Parte 5: Mantenimiento

### Actualización de Software

Cuando hay una nueva versión:

1. **Compilar nueva versión**:
   ```bash
   # Actualizar código
   git pull
   composer install
   npm install
   npm run build

   # Compilar
   compilar.bat
   ```

2. **Distribuir nuevo instalador**

3. **En cada POS**:
   - Ejecutar nuevo instalador
   - Seleccionar "Actualizar instalación existente"
   - Los datos se preservan automáticamente

### Backup de Base de Datos

**Cada POS tiene su propia base de datos** en:
```
C:\Users\[Usuario]\AppData\Local\POS System\database\database.sqlite
```

**Para hacer backup**:

```bash
# Manual
copy "C:\Users\[Usuario]\AppData\Local\POS System\database\database.sqlite" D:\Backups\

# O usar el script incluido
backup.bat
```

**Recomendación**: Backup diario automático vía script programado.

### Problemas Comunes

#### POS no conecta con Manager

**Síntoma**: Error de conexión al sincronizar

**Causas**:
1. Manager no está corriendo
2. IP incorrecta
3. Firewall bloqueando

**Solución**:
```bash
# 1. Verificar que Manager está corriendo
ping 192.168.1.100

# 2. Verificar firewall
# En el servidor Manager, abrir puerto 8000

# 3. Re-intentar sincronización
```

#### Ventas no sincronizan

**Síntoma**: Ventas se realizan pero no llegan al Manager

**Causas**:
1. Sin conexión de red
2. Token expirado
3. Error en el Manager

**Solución**:
1. Verificar red
2. Re-autenticar el POS (Configuración → Reconectar)
3. Revisar logs del Manager

#### Aplicación muy lenta

**Causas**:
1. Base de datos muy grande
2. PC con recursos limitados
3. Muchos productos sin índices

**Solución**:
1. Hacer limpieza de datos antiguos
2. Optimizar base de datos
3. Upgrade de hardware

---

## 📊 Parte 6: Monitoreo

### Desde el Manager

Puedes ver el estado de todos los POS:

```sql
-- Ver último sync de cada POS
SELECT
    id,
    nombre,
    ultima_sincronizacion,
    ventas_pendientes
FROM puntos_de_venta
ORDER BY ultima_sincronizacion DESC;
```

### Dashboard (Futuro)

Planificado agregar dashboard en el Manager mostrando:
- POS activos/inactivos
- Última sincronización
- Ventas del día por POS
- Alertas de problemas

---

## 🎓 Parte 7: Capacitación de Usuarios

### Checklist de Capacitación

- [ ] Encender/apagar POS
- [ ] Buscar productos (por nombre/código/barcode)
- [ ] Agregar productos al carrito
- [ ] Modificar cantidades
- [ ] Eliminar items
- [ ] Seleccionar método de pago
- [ ] Finalizar venta
- [ ] Sincronizar al final del día
- [ ] Qué hacer en caso de error

### Guía Rápida para Operadores

Crea un documento impreso con:

```
┌─────────────────────────────────────────┐
│  GUÍA RÁPIDA POS - CAJA 1               │
├─────────────────────────────────────────┤
│                                          │
│  INICIAR:                                │
│  • Doble click en icono "POS Caja 1"   │
│                                          │
│  BUSCAR PRODUCTO:                        │
│  • Escribir nombre o escanear código    │
│  • Enter o click para agregar           │
│                                          │
│  COBRAR:                                 │
│  • Seleccionar método pago               │
│  • Click "Finalizar Venta"              │
│                                          │
│  AL CERRAR:                              │
│  • Menú → Sincronizar                   │
│  • Archivo → Salir                      │
│                                          │
│  AYUDA:                                  │
│  • Llamar a: [TELÉFONO IT]              │
│                                          │
└─────────────────────────────────────────┘
```

---

## ✅ Checklist Final

### Antes de Implementar

- [ ] Manager configurado y accesible
- [ ] POS registrados en Manager con secrets
- [ ] Aplicación compilada y probada
- [ ] Instaladores copiados a USB/servidor
- [ ] Instrucciones escritas y claras
- [ ] Red local verificada (pings)
- [ ] Firewall configurado
- [ ] Plan de backup definido
- [ ] Usuarios capacitados

### Por Cada POS

- [ ] Instalador ejecutado
- [ ] Aplicación iniciada
- [ ] Configuración completada (URL, ID, Secret)
- [ ] Catálogo sincronizado
- [ ] Venta de prueba realizada
- [ ] Sincronización de venta verificada
- [ ] Acceso directo en escritorio
- [ ] Operador capacitado

---

## 🎯 Resumen de Comandos Útiles

```bash
# DESARROLLO
php artisan native:serve          # Ejecutar en modo desarrollo
php artisan native:build windows  # Compilar para Windows

# COMPILACIÓN RÁPIDA
compilar.bat                      # Compilar con asistente
compilar-todos-los-pos.bat       # Compilar múltiples builds

# INSTALACIÓN
instalar-pos.bat                 # Instalar proyecto base
iniciar.bat                      # Iniciar servidor web
ejecutar-modo-desarrollo.bat     # Ejecutar ventana nativa

# MANTENIMIENTO
backup.bat                       # Backup de base de datos
info-pos.bat                     # Ver info del POS
crear-acceso-directo.bat         # Crear icono en escritorio

# VERIFICACIÓN
php verificar-instalacion.php    # Verificar instalación
```

---

## 📚 Documentación Adicional

- **INSTALACION_NATIVEPHP.md**: Detalles técnicos de NativePHP
- **INSTALACION_MULTIPLE_POS.md**: Instalación sin NativePHP
- **INSTALACION.md**: Guía de instalación tradicional
- **INICIO_RAPIDO.md**: Quick start en 5 minutos
- **RESUMEN_PROYECTO.md**: Arquitectura completa

---

**¡Éxito con tu implementación Multi-POS!** 🚀

Para soporte: [tu-email@empresa.com]
