# 🏪 Instalación Múltiple de POS

Esta guía te ayudará a instalar **múltiples puntos de venta** en diferentes computadoras Windows.

## 📋 Escenarios de Instalación

### Escenario Típico
- **1 Servidor Manager**: Centralizado con base de datos principal
- **N Puntos de Venta**: Cada uno en su propia computadora Windows

Ejemplo:
```
Servidor Manager (192.168.1.100)
    ↓ sincroniza con
├─ POS 1 (Caja 1) - 192.168.1.101
├─ POS 2 (Caja 2) - 192.168.1.102
├─ POS 3 (Caja 3) - 192.168.1.103
└─ POS 4 (Deposito) - 192.168.1.104
```

## 🚀 Instalación en Cada POS

### Método 1: Instalación desde USB (RECOMENDADO) 💾

Este método es el más fácil para instalar en múltiples computadoras.

#### Preparación (Una sola vez)

1. **Copia el proyecto POS a una USB**:
   - Copia toda la carpeta `C:\MisLaravel\pos` a tu USB
   - Asegúrate de copiar TODOS los archivos incluido `.env.example`

2. **Crea un instalador portable** (opcional pero recomendado):
   - Descarga PHP portable
   - Descarga Composer
   - Descarga Node.js portable
   - Copia todo a la USB

#### Instalación en Cada Computadora

Para **cada POS**:

1. **Copia el proyecto desde la USB**:
   ```
   USB:\pos  →  C:\POS-Caja-1\
   ```

2. **Haz doble clic en** `instalar.bat`

3. **Configura el POS**:
   - ID único para cada caja (1, 2, 3, etc.)
   - La misma URL del Manager
   - Secret único para cada POS

### Método 2: Instalación desde Red Local 🌐

Si todas las computadoras están en red:

1. **Comparte el proyecto** desde una PC:
   ```
   \\PC-PRINCIPAL\POS-Instalador
   ```

2. **En cada POS**:
   ```cmd
   xcopy \\PC-PRINCIPAL\POS-Instalador C:\POS-Caja-1 /E /I
   cd C:\POS-Caja-1
   instalar.bat
   ```

### Método 3: Clonar Instalación Existente 📋

Si ya instalaste un POS y funciona, puedes clonar la instalación:

1. **Copia la carpeta completa** del POS funcionando
2. **En la nueva computadora**, elimina:
   ```
   database\database.sqlite
   .env
   ```
3. **Ejecuta**:
   ```cmd
   instalar.bat
   ```

## 🔑 Configuración de Cada POS

### Información que Necesitas

Antes de configurar, ten a mano:

| POS | ID | Secret | IP Local |
|-----|-------|--------|----------|
| Caja 1 | 1 | secret-caja-1 | 192.168.1.101 |
| Caja 2 | 2 | secret-caja-2 | 192.168.1.102 |
| Caja 3 | 3 | secret-caja-3 | 192.168.1.103 |
| Depósito | 4 | secret-deposito | 192.168.1.104 |

### Generar Secrets en el Manager

En el servidor Manager, genera un secret para cada POS:

```bash
# En el manager
php artisan tinker

# Para cada POS
$secret = \Illuminate\Support\Str::random(32);
$pos = \App\Models\PuntoDeVenta::create([
    'sucursal_id' => 1,
    'nombre' => 'Caja 1',
    'secret' => bcrypt($secret),
    'activo' => true,
]);

echo "ID: {$pos->id}\n";
echo "Secret: {$secret}\n";
// GUARDA ESTE SECRET - Lo necesitarás en el POS
```

## 📁 Estructura Recomendada en Cada PC

```
C:\
├── POS-Caja-1\          (o nombre descriptivo)
│   ├── app\
│   ├── database\
│   │   └── database.sqlite    (única para cada POS)
│   ├── .env                   (configuración única)
│   ├── instalar.bat
│   └── iniciar.bat
```

## 🎯 Script de Instalación Mejorado

He creado un instalador que te pregunta el ID del POS:

**`instalar-pos.bat`** (próximo a crear)

## ⚙️ Configuración Específica por POS

### En el `.env` de cada POS:

```env
APP_NAME="POS - Caja 1"          # ← Cambiar por cada caja
DB_CONNECTION=sqlite
DB_DATABASE=C:\POS-Caja-1\database\database.sqlite  # ← Ruta específica

MANAGER_API_URL=http://192.168.1.100:8000/api      # ← Mismo para todos
```

## 🔄 Proceso de Instalación Completo

### Checklist por POS

- [ ] **Copia archivos** a la carpeta específica
- [ ] **Ejecuta** `instalar.bat`
- [ ] **Inicia** `iniciar.bat` o `php artisan serve`
- [ ] **Abre** navegador en `http://localhost:8000/configuracion`
- [ ] **Ingresa**:
  - URL Manager: `http://192.168.1.100:8000/api`
  - ID del POS: `1` (único para cada caja)
  - Secret: `secret-caja-1` (único para cada caja)
- [ ] **Sincroniza** catálogo inicial
- [ ] **Prueba** una venta de prueba
- [ ] **Verifica** que sincroniza correctamente

## 🆘 Troubleshooting Multi-POS

### Problema: Dos POS con el mismo ID

**Síntoma**: Conflictos en las ventas, datos sobrescritos

**Solución**: Cada POS DEBE tener un ID único
```
POS 1 → ID: 1
POS 2 → ID: 2
POS 3 → ID: 3
```

### Problema: No encuentra el Manager

**Causa**: IP incorrecta o Manager no accesible desde la red

**Solución**:
1. Verifica que todas las PCs estén en la misma red
2. Prueba hacer ping desde el POS:
   ```cmd
   ping 192.168.1.100
   ```
3. Verifica que el Manager esté corriendo
4. Verifica firewall del servidor Manager

### Problema: Secret inválido

**Causa**: Secret mal copiado o no coincide

**Solución**:
1. Regenera el secret en el Manager
2. Copia el secret CON CUIDADO (sin espacios)
3. Reconfigura el POS con el nuevo secret

## 📊 Gestión de Múltiples POS

### Nombrado Consistente

Usa nombres descriptivos:

```
C:\POS-Principal\      → Caja principal
C:\POS-Secundaria\     → Caja secundaria
C:\POS-Deposito\       → POS de depósito
C:\POS-Mostrador\      → POS de atención
```

### Backup de Cada POS

Cada POS tiene su propia base de datos. Para backup:

```cmd
REM Hacer backup manual
copy database\database.sqlite backups\backup-%date%.sqlite

REM O usar el script de backup (próximo a crear)
backup.bat
```

### Identificación Visual

En cada POS, el header muestra:
- **Nombre del POS**: "Caja 1"
- **Sucursal**: "Sucursal Centro"

Esto ayuda al operador a saber en qué caja está.

## 🔐 Seguridad Multi-POS

### Recomendaciones:

1. **Secret único** por cada POS
2. **No compartir** secrets entre POS
3. **Bloquear** acceso a `database\database.sqlite` (solo lectura para usuarios)
4. **Backup diario** automático de cada POS
5. **Red privada** (no exponer a internet)

## 🎓 Capacitación de Usuarios

### Guía Rápida para Operadores

1. **Encender PC**
2. **Doble clic** en `iniciar.bat` (o acceso directo en escritorio)
3. **Esperar** a que cargue
4. **Usar** el POS normalmente
5. **Al cerrar**, click en "Sincronizar" antes de apagar

### Acceso Directo en Escritorio

Crea un acceso directo para cada POS:

1. Click derecho en `iniciar.bat`
2. "Enviar a" → "Escritorio (crear acceso directo)"
3. Renombrar a: "POS Caja 1"
4. Cambiar ícono (opcional)

## 📈 Escalabilidad

Este sistema soporta:

- ✅ **Hasta 50+ POS** por Manager
- ✅ **Múltiples sucursales**
- ✅ **Sincronización distribuida**
- ✅ **Cada POS funciona offline**

## 🔄 Actualización de Múltiples POS

Cuando hay una nueva versión:

### Opción A: Actualización Manual
1. Copia los archivos nuevos
2. Mantén `.env` y `database\database.sqlite`
3. Ejecuta:
   ```cmd
   composer install
   npm install
   php artisan migrate
   npm run build
   ```

### Opción B: Script de Actualización (próximo)
```cmd
actualizar.bat
```

## 🎯 Resumen Quick Start Multi-POS

Para cada nuevo POS:

```cmd
REM 1. Copiar proyecto
xcopy C:\POS-Plantilla C:\POS-Caja-X /E /I

REM 2. Instalar
cd C:\POS-Caja-X
instalar.bat

REM 3. Iniciar
iniciar.bat

REM 4. Configurar en navegador
REM    http://localhost:8000/configuracion
REM    ID: X (único)
REM    Secret: secret-caja-X (único)
```

## 📞 Soporte

Para problemas con múltiples POS:

1. Verifica red local (ping entre PCs)
2. Verifica Manager esté corriendo
3. Verifica IDs únicos para cada POS
4. Revisa logs en cada POS: `storage\logs\laravel.log`

---

**Versión**: 1.0.0
**Actualizado**: Febrero 2026
