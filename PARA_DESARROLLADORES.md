# 👨‍💻 Guía para Desarrolladores

## 🎯 Importante: Dos Modos de Trabajo

Este proyecto POS tiene **DOS MODOS** de operación:

### Modo 1: Desarrollo (ESTE MODO REQUIERE PHP)
Para **desarrollar y compilar** la aplicación

### Modo 2: Producción (NO REQUIERE PHP)
Para **usuarios finales** que instalan el `.exe`

---

## 🛠️ Para Desarrolladores (TÚ)

**Sí necesitas PHP, Composer, Node.js**

### Problema Actual: Git Bash no encuentra PHP

Esto es **SOLO para desarrollo**. Hay varias soluciones:

#### ✅ Solución 1: Usar PowerShell/CMD (RECOMENDADO)

En lugar de Git Bash, usa:

```powershell
# PowerShell o CMD
cd C:\MisLaravel\pos
instalar-pos.bat
```

#### ✅ Solución 2: Usar Laravel Herd

Si tienes Laravel Herd instalado:

1. Abre Herd
2. Agrega `C:\MisLaravel\pos` como sitio
3. Ya está disponible en `http://pos.test`

```powershell
# Solo instalar dependencias
cd C:\MisLaravel\pos
composer install
npm install
php artisan migrate
npm run build
```

#### ✅ Solución 3: Agregar PHP al PATH de Git Bash

Solo si REALMENTE quieres usar Git Bash:

```bash
# Encuentra dónde está PHP
where.exe php

# Edita ~/.bashrc
nano ~/.bashrc

# Agrega (ajusta la ruta según tu instalación):
export PATH="/c/Users/Pablo Navarro/AppData/Local/herd/bin:$PATH"

# Recarga
source ~/.bashrc
```

---

## 👥 Para Usuarios Finales (LOS QUE USAN EL POS)

**NO necesitan NADA instalado**

### ¿Cómo funciona?

1. **Tú compilas** la aplicación con NativePHP:
   ```powershell
   composer require nativephp/electron
   php artisan native:install electron
   compilar.bat
   ```

2. Se genera: `dist/POS-System-Setup.exe` (~200 MB)

3. **Este .exe incluye TODO**:
   - PHP embebido
   - Base de datos SQLite
   - Servidor web interno
   - La aplicación Laravel completa
   - Todo el runtime necesario

4. El usuario final solo:
   - Doble click en `POS-System-Setup.exe`
   - Next, Next, Install
   - ¡Listo! No necesita instalar NADA más

---

## 🔄 Flujo de Trabajo Completo

### Fase 1: Desarrollo (Tu PC)

```powershell
# En PowerShell o CMD (NO Git Bash)
cd C:\MisLaravel\pos

# 1. Instalar dependencias (primera vez)
composer install
npm install

# 2. Configurar
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate
npm run build

# 3a. Probar en modo web
php artisan serve
# Abre: http://localhost:8000

# 3b. O probar en modo nativo (ventana de escritorio)
php artisan native:serve
```

### Fase 2: Compilación (Tu PC)

```powershell
# Instalar NativePHP (solo primera vez)
composer require nativephp/electron
php artisan native:install electron

# Compilar aplicación
compilar.bat
# o
php artisan native:build windows

# Resultado: dist/POS-System-Setup.exe
```

### Fase 3: Distribución (PCs de Usuario Final)

```
1. Copiar POS-System-Setup.exe a USB
2. Llevar a cada computadora
3. Doble click → Instalar
4. Configurar (URL Manager, ID, Secret)
5. ¡Listo!
```

**El usuario NO necesita**:
- ❌ PHP
- ❌ Composer
- ❌ Node.js
- ❌ Configurar PATH
- ❌ Servidor web
- ❌ Nada técnico

---

## 📦 ¿Qué incluye el .exe?

El instalador NativePHP empaqueta:

```
POS-System-Setup.exe (~200 MB)
├── PHP 8.2+ embebido
├── Electron framework
├── SQLite
├── Aplicación Laravel completa
│   ├── Código PHP
│   ├── Assets compilados (CSS/JS)
│   └── Migraciones
└── Runtime completo
```

Todo en **UN SOLO ARCHIVO** instalable.

---

## 🆚 Comparación

| Aspecto | Desarrollo | Producción (Usuario Final) |
|---------|------------|---------------------------|
| **Requiere PHP** | ✅ Sí | ❌ No (embebido) |
| **Requiere Composer** | ✅ Sí | ❌ No |
| **Requiere Node.js** | ✅ Sí | ❌ No |
| **Configurar PATH** | ✅ A veces | ❌ Nunca |
| **Instalación** | Manual | Doble click |
| **Complejidad** | Alta | Baja |
| **Tiempo setup** | 10-15 min | 2-3 min |
| **Conocimientos** | Técnicos | Ninguno |

---

## 🔧 Comandos de Desarrollo

### Instalación Inicial

```powershell
# Opción A: Automatizada
instalar-pos.bat

# Opción B: Manual
composer install
npm install
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate
npm run build
```

### Desarrollo Diario

```powershell
# Modo web (navegador)
php artisan serve

# Modo nativo (ventana Electron)
php artisan native:serve
```

### Compilación

```powershell
# Build único
compilar.bat

# Builds múltiples (varias cajas)
compilar-todos-los-pos.bat
```

### Mantenimiento

```powershell
# Limpiar caché
php artisan optimize:clear

# Migrar base de datos
php artisan migrate

# Sincronizar datos (testing)
php artisan pos:sync

# Ver información
info-pos.bat
```

---

## 🐛 Debugging

### En Desarrollo

```powershell
# Ver logs
tail -f storage/logs/laravel.log

# Tinker (consola interactiva)
php artisan tinker

# Ver configuración
php artisan config:show

# Verificar instalación
php verificar-instalacion.php
```

### En Producción (Usuario Final)

Los logs están en:
```
C:\Users\[Usuario]\AppData\Local\POS System\logs\
```

---

## 📚 Recursos para Desarrolladores

### Documentación Técnica

- **RESUMEN_PROYECTO.md** - Arquitectura completa
- **INSTALACION_NATIVEPHP.md** - Detalles de NativePHP
- **CHANGELOG.md** - Historial de cambios

### Estructura del Código

```
app/
├── Models/          → 7 modelos Eloquent
├── Services/        → Lógica de negocio
├── Livewire/        → Componentes UI
└── Providers/       → Config NativePHP

database/
└── migrations/      → 7 migraciones SQLite

resources/
├── views/           → Vistas Blade
├── css/             → Tailwind CSS
└── js/              → Alpine.js
```

### APIs y Servicios

- **ManagerApiService** - Cliente HTTP para Manager
- **SyncService** - Sincronización bidireccional

---

## 🎓 Aprendizaje

### Si eres nuevo en NativePHP

1. Lee: https://nativephp.com/docs
2. Revisa: `config/native.php`
3. Explora: `app/Providers/NativeAppServiceProvider.php`

### Si eres nuevo en Livewire

1. Lee: https://livewire.laravel.com
2. Revisa: `app/Livewire/Pos/Venta.php`
3. Explora: `resources/views/livewire/`

---

## ✅ Checklist de Desarrollo

### Antes de Compilar

- [ ] Código tested localmente
- [ ] Assets compilados (`npm run build`)
- [ ] Migraciones probadas
- [ ] Iconos agregados (`resources/images/`)
- [ ] Versión actualizada en `config/native.php`
- [ ] CHANGELOG.md actualizado

### Antes de Distribuir

- [ ] Compilación exitosa
- [ ] Instalador probado en PC limpia
- [ ] Sincronización con Manager verificada
- [ ] Venta de prueba realizada
- [ ] Documentación actualizada
- [ ] Secrets generados para cada POS

---

## 🚀 Resumen para Ti

### TU TRABAJO (Desarrollador):

1. **Desarrollar** en tu PC (necesitas PHP, Composer, Node.js)
2. **Compilar** con NativePHP (`compilar.bat`)
3. **Distribuir** el `.exe` generado

### TRABAJO DEL USUARIO FINAL:

1. **Instalar** (doble click en .exe)
2. **Configurar** (URL, ID, Secret)
3. **Usar** (vender productos)

**NO necesitan conocimientos técnicos ni instalar dependencias.**

---

## 💡 Tips

### Para Desarrollo Rápido

```powershell
# Usa el modo nativo (más realista)
php artisan native:serve

# Hot reload está activado
# Cambios en código se reflejan automáticamente
```

### Para Debugging

```powershell
# En NativePHP puedes abrir DevTools
# Menu → View → DevTools
# O presiona F12 en la ventana nativa
```

### Para Testing Multi-POS

```powershell
# Compila builds con diferentes IDs
compilar.bat  # Opción 2, ID: 1
compilar.bat  # Opción 2, ID: 2

# Prueba ambos al mismo tiempo
```

---

**¿Dudas sobre desarrollo?** Revisa `RESUMEN_PROYECTO.md` para arquitectura detallada.

**¿Listo para distribuir?** Sigue `GUIA_COMPLETA_MULTI_POS.md`.
