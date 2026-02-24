# 🚀 Inicio Rápido - POS System

Esta es la guía más rápida para poner en marcha tu POS.

## ⚡ Instalación Express (5 minutos)

### 1️⃣ Instalar Dependencias

```bash
cd C:\MisLaravel\pos

# Instalar dependencias PHP
composer install

# Instalar dependencias JavaScript
npm install
```

### 2️⃣ Configurar Entorno

```bash
# Copiar archivo de configuración
copy .env.example .env

# Generar clave de aplicación
php artisan key:generate

# Crear base de datos SQLite
type nul > database\database.sqlite
```

### 3️⃣ Ejecutar Migraciones

```bash
php artisan migrate
```

### 4️⃣ Compilar Assets

```bash
npm run build
```

### 5️⃣ Iniciar Servidor

```bash
php artisan serve
```

### 6️⃣ Configurar POS

1. Abrir en navegador: http://localhost:8000/configuracion

2. Completar datos:
   - **URL del Manager**: `http://192.168.1.100:8000/api` (tu servidor)
   - **ID del POS**: `1` (el que te asignaron)
   - **Clave Secreta**: (la clave que te dieron)

3. Click en "Conectar y Configurar"

4. Click en "Sincronizar Catálogo Inicial"

5. ¡Listo! Ya puedes vender 🎉

---

## 🔍 Verificar Instalación

Antes de empezar, puedes ejecutar el script de verificación:

```bash
php verificar-instalacion.php
```

Te mostrará si falta algo por configurar.

---

## 📋 Checklist Rápido

- [ ] PHP 8.2+ instalado
- [ ] Composer instalado
- [ ] Node.js instalado
- [ ] Dependencias instaladas (`composer install` y `npm install`)
- [ ] Archivo `.env` creado
- [ ] APP_KEY generada
- [ ] Base de datos SQLite creada
- [ ] Migraciones ejecutadas
- [ ] Assets compilados
- [ ] Servidor iniciado
- [ ] POS configurado con Manager
- [ ] Catálogo sincronizado

---

## 🎯 Uso Básico

### Realizar una Venta

1. **Buscar producto**: Escribe nombre/código o escanea código de barras
2. **Agregar al carrito**: Click en el producto o Enter si es único
3. **Ajustar cantidad**: Botones +/- en cada item
4. **Seleccionar pago**: Efectivo, Tarjeta o Transferencia
5. **Finalizar**: Click en "Finalizar Venta"

### Sincronizar

- **Manual**: Menu (⋮) → "Sincronizar"
- **Por comando**: `php artisan pos:sync`

---

## 🆘 Ayuda Rápida

### No puedo conectar con el Manager

✅ Verifica que el Manager esté corriendo
✅ Verifica la URL (debe terminar en `/api`)
✅ Prueba hacer ping a la IP del servidor

### Los productos no aparecen

✅ Verifica que se haya sincronizado: Menu → Sincronizar
✅ Verifica en el Manager que los productos tengan `es_vendible = true`

### Error al finalizar venta

✅ Verifica que haya stock disponible
✅ Revisa los logs: `storage/logs/laravel.log`

---

## 📚 Más Información

- **Documentación completa**: Ver `README.md`
- **Guía de instalación**: Ver `INSTALACION.md`
- **Detalles técnicos**: Ver `RESUMEN_PROYECTO.md`

---

## 💡 Tips

- **Búsqueda rápida**: Puedes buscar por cualquier parte del nombre
- **Código de barras**: Escanea directamente con el lector
- **Un producto único**: Si la búsqueda encuentra 1 producto, se agrega automáticamente
- **Sincroniza seguido**: Mantén sincronizado el POS para tener datos actualizados
- **Modo offline**: Puedes vender sin conexión y sincronizar después

---

¡Disfruta vendiendo con tu nuevo POS! 🛒✨
