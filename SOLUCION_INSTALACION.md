# 🔧 Solución: Instalación en Windows

## Problema: PHP no encontrado en Git Bash

Git Bash no encuentra PHP porque no está en el PATH de Git Bash.

## ✅ Soluciones

### Opción 1: Usar Scripts .BAT (MÁS FÁCIL) ⚡

He creado scripts automáticos para Windows:

#### 1. Instalar el proyecto

Haz **doble clic** en:
```
instalar.bat
```

O desde **CMD/PowerShell**:
```cmd
cd C:\MisLaravel\pos
instalar.bat
```

Este script hará todo automáticamente:
- ✅ Instala dependencias PHP (composer install)
- ✅ Instala dependencias JavaScript (npm install)
- ✅ Crea archivo .env
- ✅ Genera APP_KEY
- ✅ Crea base de datos SQLite
- ✅ Ejecuta migraciones
- ✅ Compila assets

#### 2. Iniciar el servidor

Haz **doble clic** en:
```
iniciar.bat
```

O desde **CMD/PowerShell**:
```cmd
iniciar.bat
```

### Opción 2: Usar PowerShell/CMD Manualmente

Abre **PowerShell** o **CMD** (no Git Bash) y ejecuta:

```powershell
cd C:\MisLaravel\pos

# Instalar dependencias
composer install
npm install

# Configurar
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite

# Migrar
php artisan migrate

# Compilar assets
npm run build

# Iniciar servidor
php artisan serve
```

### Opción 3: Usar Laravel Herd (RECOMENDADO)

Si tienes **Laravel Herd** instalado (que parece que sí):

1. Abre **Herd**
2. Agrega el directorio `C:\MisLaravel\pos` como sitio
3. Herd automáticamente servirá el proyecto en una URL como:
   ```
   http://pos.test
   ```

Luego solo ejecuta en PowerShell:
```powershell
cd C:\MisLaravel\pos
composer install
npm install
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate
npm run build
```

### Opción 4: Agregar PHP al PATH de Git Bash

Si prefieres usar Git Bash, agrega PHP al PATH:

1. Encuentra dónde está PHP (generalmente en):
   - `C:\xampp\php`
   - `C:\laragon\bin\php\php-8.x`
   - `C:\Users\TuUsuario\AppData\Local\herd\bin`

2. Edita `~/.bashrc` en Git Bash:
   ```bash
   nano ~/.bashrc
   ```

3. Agrega al final:
   ```bash
   export PATH="/c/Users/Pablo Navarro/AppData/Local/herd/bin:$PATH"
   ```

4. Recarga:
   ```bash
   source ~/.bashrc
   ```

5. Verifica:
   ```bash
   php -v
   ```

## 🚀 Instalación Recomendada (Paso a Paso)

### ⭐ OPCIÓN MÁS FÁCIL: Usar instalar.bat

1. Ve al directorio del proyecto en el Explorador de Windows:
   ```
   C:\MisLaravel\pos
   ```

2. Haz **doble clic** en el archivo:
   ```
   instalar.bat
   ```

3. Espera a que termine (2-5 minutos)

4. Cuando termine, haz **doble clic** en:
   ```
   iniciar.bat
   ```

5. Abre tu navegador en:
   ```
   http://localhost:8000/configuracion
   ```

6. ¡Listo! Configura tu POS 🎉

## 🔍 Verificar Instalación

Después de instalar, ejecuta:

```cmd
php verificar-instalacion.php
```

Te dirá si algo falta o si todo está OK.

## ⚠️ Problemas Comunes

### "php no es reconocido como comando"

**Causa**: PHP no está instalado o no está en el PATH

**Solución**:
1. Instala Laravel Herd: https://herd.laravel.com
2. O instala XAMPP: https://www.apachefriends.org
3. O verifica que PHP esté en el PATH del sistema

### "composer no es reconocido como comando"

**Causa**: Composer no está instalado

**Solución**:
1. Descarga e instala Composer: https://getcomposer.org/download/
2. Reinicia la terminal después de instalar

### "node no es reconocido como comando"

**Causa**: Node.js no está instalado

**Solución**:
1. Descarga e instala Node.js: https://nodejs.org
2. Reinicia la terminal después de instalar

### "npm run build" falla

**Causa**: Dependencias de npm no instaladas

**Solución**:
```cmd
npm install
npm run build
```

## 📞 Ayuda Adicional

Si tienes problemas:

1. **Revisa los logs**: `storage\logs\laravel.log`
2. **Ejecuta verificación**: `php verificar-instalacion.php`
3. **Revisa los requisitos**:
   - PHP 8.2+
   - Composer 2.x
   - Node.js 18.x+
   - Extensiones PHP: pdo_sqlite, openssl, mbstring, curl

## 🎯 Resumen Rápido

```cmd
REM Opción más fácil:
instalar.bat      # Instalar todo
iniciar.bat       # Iniciar servidor

REM O manualmente en PowerShell/CMD:
composer install
npm install
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate
npm run build
php artisan serve
```

¡Y listo! Tu POS estará funcionando 🚀
