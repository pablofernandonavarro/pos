# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es esto

Una app de Punto de Venta (POS) en Laravel 12 + Livewire 4, pensada para correr como **aplicación de escritorio offline-first** vía NativePHP/Electron, con **SQLite** como base de datos. Es el nodo "caja registradora" de un sistema más grande: un "Manager" separado (un servidor remoto, que no forma parte de este repo) es la fuente de verdad del catálogo, precios y stock; este POS sincroniza periódicamente con él. Todo acá debe funcionar completamente offline, con la sincronización como un paso de reconciliación manual/en segundo plano, no como una dependencia en tiempo de ejecución.

### Un solo código, dos formas de instalar

- **Clásica** (PHP + tareas programadas en la máquina, ej. Caja 1): `composer.json`/`composer.lock`, **sin NativePHP**. Las cajas clásicas corren con PHP 8.2 (XAMPP) y `nativephp/desktop` pide ≥ 8.3 (su lock, 8.4): agregarlo a `composer.json` deja esas cajas sin arrancar.
- **Escritorio** (ejecutable NativePHP/Electron, ej. caja 2 en `C:\POS-Escritorio`): `composer.escritorio.json`/`composer.escritorio.lock` = lo mismo + `nativephp/desktop` fijo en 2.3.0. Se compila con `compilar-escritorio.bat -Version X.Y.Z` (ver `compilar-escritorio.ps1`).
- **No hay más `pos-native`**: era una copia a mano y se unificó acá. Todo cambio va a este repo.
- El código de escritorio siempre se protege: `class_exists(\Native\Desktop\Facades\...)` / `config('nativephp-internal.running')` (`EscritorioService`, `VersionPos`, `ImpresoraNativePHP`). En la clásica esas clases no existen.
- **Una dependencia nueva va en los dos `composer*.json`**; el script de compilación compara los `require` y aborta si difieren. Después de tocar `composer.escritorio.json`, el script actualiza `composer.escritorio.lock` en el repo (commitearlo).
- `compilar-escritorio.ps1` **nunca compila en esta carpeta** (tiene `.env`, base e identidad de una caja real y NativePHP mete en el ejecutable lo que encuentra): arma `..\pos-build-escritorio` con robocopy, pone ahí `composer.escritorio.*` como `composer.json/lock` (NativePHP corre `composer install --no-dev` al compilar: con el `composer.json` clásico se desinstalaría a sí mismo), el `.env` desde `.env.escritorio` (no versionado, lleva la `APP_KEY` que comparten todas las compilaciones; plantilla `.env.escritorio.example`), corre los tests con NativePHP instalado, compila y revisa que no se hayan colado `.sqlite`/`.pos-info`. Exige versión mayor a la última (`NATIVEPHP_APP_VERSION` en `.env.escritorio`): **sin subir la versión las cajas no corren migraciones**. `-SoloPreparar` arma la copia para probar con `php artisan native:run`; `-Manager <ruta>` además publica con `pos:publicar-escritorio`.
- `nativephp/desktop` 2.3.0 viene sin `electron-plugin/dist/server/pdfPageSize.js` y la app no arranca: `scripts/parchear-nativephp.php` (post-install de composer) copia el de `parches/nativephp-desktop-2.3.0/`. Al actualizar el paquete, verificar si ya lo trae.
- NSIS falla en esta máquina: se distribuye la carpeta `win-unpacked` (zip vía el Manager). El `native:build` puede terminar con error y aun así dejarla bien; el script lo distingue.

La impresora térmica de destino para el ticket de venta es una **Epson TM-T20** (impresión de tickets vía ESC/POS). Todavía no hay código de impresión implementado en el repo (figura como pendiente en `PROYECTO_COMPLETO.md`/`RESUMEN_PROYECTO.md`/`CHANGELOG.md`); cuando se implemente, integrar contra ese modelo.

## Comandos

El desarrollo se hace en Windows; la propia documentación del proyecto (`PARA_DESARROLLADORES.md`) advierte que **Git Bash muchas veces no encuentra PHP** — preferir PowerShell/CMD, o Laravel Herd, para correr comandos `php`/`artisan`/`composer`.

```powershell
# Instalación
composer install
npm install
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate

# Assets
npm run dev      # servidor de desarrollo vite
npm run build    # build de producción

# Ejecutar (modo web, navegador)
php artisan serve

# Ejecutar (ventana nativa de escritorio, requiere NativePHP instalado)
php artisan native:serve

# Sincronizar con el Manager (manual/testing)
php artisan pos:sync            # bidireccional (pull y luego push)
php artisan pos:sync --pull     # solo pull: productos, precios, stock
php artisan pos:sync --push     # solo push: ventas, movimientos_stock

# Sincronización programada (si se configura como tarea periódica)
php artisan schedule:work

# App de escritorio: compilar desde una copia limpia (PHP 8.4 de Herd, ver "Un solo código")
compilar-escritorio.bat -Version 1.3.0
compilar-escritorio.bat -SoloPreparar      # copia en ..\pos-build-escritorio para native:run
```

**Tests**: `php vendor/bin/phpunit` (con el PHP de Herd). SQLite en memoria, forzado en `phpunit.xml` con `force="true"` porque el `.env` de esta carpeta es el de una caja real. `tests/TestCase.php` llama a `Http::preventStrayRequests()`: ningún test habla con un Manager real, los que necesitan respuestas usan `Http::fake()`. Helpers `configurarCaja()` y `producto()`. `compilar-escritorio.ps1` los vuelve a correr con NativePHP instalado antes de compilar. No hay `pint.json` (Pint usaría el ruleset por defecto).

`config/` solo contiene `native.php` — el proyecto se apoya en que Laravel 12 puede correr sin publicar los archivos de config estándar (`app.php`, `database.php`, etc.), configurándose todo vía `.env`.

## Arquitectura

### La división en dos sistemas

**Los `.bat` y `.vbs` tienen que guardarse con saltos CRLF.** Con LF, `cmd.exe` ejecuta la primera línea y después se comporta de forma errática: `instalar-pos.bat` mostraba el encabezado y nunca llegaba a preguntar el nombre de la caja. Casi cualquier editor o herramienta que escriba LF rompe el instalador, y el síntoma no se parece en nada a la causa. Por eso `pos:empaquetar` y `pos:kit` **normalizan a CRLF al armar el paquete** en vez de confiar en el origen.

**Para instalar en otra máquina se usa `preparar-kit.bat`**, que genera una copia limpia en `..\pos-kit`. **Nunca copiar la carpeta de una caja directamente**: lleva su `.env`, su `.pos-info` y su `database.sqlite` — o sea su identidad, su token y sus ventas. La máquina nueva arrancaría creyendo que *es* esa caja y sincronizaría con su token, con dos terminales compartiendo identidad. El kit sí incluye `vendor/` y `node_modules/` para que la instalación funcione sin internet, y el script aborta si detecta que alguno de esos archivos se coló.

**Alta de una caja**: no se configura a mano. Un usuario del Manager genera un código de instalación (`Puntos de venta` → botón `Código`) y en la máquina se corre `php artisan pos:provision <codigo>` — el paso 9 de `instalar-pos.bat` lo pide. El comando canjea el código, guarda url/credenciales en `configuracion`, autentica y baja el catálogo. Sirve igual para reinstalar una caja rota: al canjear, el Manager rota el secret y revoca los tokens viejos. En la **app de escritorio** no hay consola: `/configuracion` (`Configuracion\Inicial`) pide dirección del Manager + código y usa el mismo `ProvisionService` que el comando; el middleware `RequiereCajaConfigurada` manda ahí a toda caja sin configurar, y `EscritorioService` deja el inicio con Windows y el acceso directo (solo si corre dentro de NativePHP). La carga manual con id + secret quedó como opción de soporte en esa misma pantalla. La app de escritorio sale de este mismo repo (ver "Un solo código, dos formas de instalar").

**`app/Console/Commands/OptimizeCommand.php` reemplaza al `optimize` de Laravel y en escritorio no cachea la configuración.** NativePHP corre `artisan optimize` al arrancar una versión nueva con el secreto de Electron y el puerto de su API de ese arranque; `config:cache` los congelaba en `bootstrap/cache/config.php` y en el arranque siguiente Electron rechazaba todos los pedidos (`abort()` sin mensaje y `cURL error 7 ... port 4002` en el log). La caja quedaba abierta pero muerta, típicamente al reiniciar la PC. No volver a cachear config en la app de escritorio.

Este repo es el **POS** (punto de venta, uno por caja). Se comunica por HTTP con un **Manager** separado (fuente de verdad de catálogo/precios/stock) a través de `MANAGER_API_URL`. El Manager es otro proyecto Laravel que vive en **`C:\MisLaravel\manager`** (MySQL vía Docker/Sail); sus endpoints de sync están en `app/Http/Controllers/Api/V1/SyncController.php` y se declaran en `routes/api.php`. Cualquier cambio en el contrato de sync hay que hacerlo en los dos lados a la vez. Cada instancia de POS se autentica con su propio `punto_de_venta_id` + secreto y obtiene un token bearer que se guarda localmente. Todo esto —URL, credenciales, token, timestamps de sincronización— vive en la tabla clave/valor `configuracion` (ver `App\Models\Configuracion::get()`/`::set()`), no en los archivos de config de Laravel ni en el `.env` en runtime. `Configuracion::isConfigured()` determina si la app muestra la UI principal del POS o fuerza `/configuracion` (`App\Livewire\Configuracion\Inicial`).

### Flujo de sincronización (`App\Services\SyncService` + `App\Services\ManagerApiService`)

- **Pull** (Manager → POS): `syncProductos()`, `syncPrecios()`, `syncStock()`, agrupados en `syncInicial()` dentro de una transacción de base de datos (hace rollback de las tres si alguna falla). Los productos se actualizan/crean (upsert) por el `id` del Manager, **forzado con `forceFill`**: `id` no es fillable y con `updateOrCreate` SQLite asignaba su autoincremental. Como el Manager no manda los padres de los configurables, hay huecos en su numeración y los ids quedaban corridos: las ventas descontaban stock de otro artículo. `productos.id` local **tiene que ser** el id del Manager (viaja en ventas, stock y precios). Con el catálogo completo (`ultima_sincronizacion_productos` vacía) `realinearIdsDeProductos()` corrige cajas viejas; mientras esa marca esté vacía, `syncStock()` baja primero el catálogo entero y `pushVentas()`/`pushMovimientos()` retienen el envío; `syncPrecios()` **trunca y reconstruye por completo** la tabla `precios` local en cada corrida (no es incremental); la sincronización de stock actualiza `productos.stock` directamente por id de producto.
- **`syncPrecios()` también borra las listas que el Manager dejó de asignar** (`limpiarListasObsoletas()`). No es cosmético: antes quedaban listas viejas conviviendo con las vigentes y **varias con `es_default = true`**, y como `ListaPrecio::getDefault()` resuelve con un `first()`, la caja podía cobrar con el factor de una lista que ya no correspondía. Una lista referenciada por alguna venta **no se borra** —se conserva por trazabilidad y la foreign key lo impediría igual— pero se le quita el flag de default.
- **Push** (POS → Manager): `pushVentas()` y `pushMovimientos()` envían todo lo que aún no esté marcado `sincronizado = true` (ver `Venta::scopePendientes()` / `MovimientoStock`).
- **Idempotencia (importante)**: cada `Venta` y cada `MovimientoStock` lleva un `uuid` generado en la caja (hook `creating` en cada modelo) que viaja en el payload. El Manager tiene índice único sobre `uuid` y descarta lo que ya procesó, devolviendo un ack por operación en `resultados[]` (`status` = `creada`/`duplicada`). El POS marca como sincronizado **solo lo que vino en ese ack**; lo que no, queda pendiente y se reintenta. Esto es lo que hace seguro el `retry(3, 100)` de `ManagerApiService`: antes, un POST que llegaba pero cuya respuesta se perdía duplicaba la venta en el Manager. **Nunca envíes una venta o movimiento sin `uuid`** — el Manager lo rechaza por validación.
- **Los movimientos de tipo `venta` no se envían por `/sync/movimientos`**: el Manager ya los crea él mismo al procesar la venta en `/sync/ventas` (y ahí descuenta `stock_sucursal`). Se filtran explícitamente en `pushMovimientos()` y se marcan sincronizados vía `marcarMovimientosDeVentaSincronizados()` cuando el Manager confirma la venta que los originó. Mandarlos por ambos lados descuenta el stock dos veces.
- `syncBidireccional()` = pull y luego push; el éxito general requiere que los tres sub-resultados sean exitosos.
- **Toda llamada al Manager lleva `X-POS-Version` y `X-POS-Tipo`** (`App\Support\VersionPos::cabeceras()`), con eso el Manager muestra qué versión corre cada caja. Escritorio informa `NATIVEPHP_APP_VERSION`; clásica, el archivo `VERSION`. Una llamada nueva a la API que no pase por `client()` tiene que agregar esas cabeceras a mano.
- `ManagerApiService` envuelve todas las llamadas HTTP al Manager (`Http::timeout(30)->retry(3, 100)`), y siempre devuelve `['success' => bool, ...]` en vez de lanzar excepciones — quien lo llama debe ramificar según `success`, no capturar excepciones de estos métodos.
- `php artisan pos:sync` (`App\Console\Commands\SyncCommand`) es el punto de entrada por CLI.
- **Sincronización casi en tiempo real (cola)**: al cerrar una venta, `Pos\Venta::finalizarVenta()` despacha `App\Jobs\SincronizarPendientes` **fuera de la transacción**, y un `queue:work` la envía al Manager en segundos. El job es `ShouldBeUnique` (`uniqueFor = 30`) para que varias ventas seguidas no encolen trabajo redundante: un solo push manda todo lo pendiente.
- **`QUEUE_CONNECTION` debe ser `database`, nunca `sync`.** Con `sync` el job correría inline dentro del request de la venta y la caja quedaría esperando a la red — `ManagerApiService` usa `timeout(30)->retry(3)`, o sea hasta ~90s con el Manager caído. Despachar a la cola cuesta ~5ms.
- **Red de seguridad del push**: `routes/console.php` programa `pos:sync --push` cada 5 minutos (`withoutOverlapping`), para cuando el worker estuvo caído o el job agotó sus 3 reintentos.
- **Órdenes desde el Manager**: `pos:comandos` corre **cada minuto** y ejecuta lo que el Manager haya encolado para esta caja (botón *Reparar* en `Puntos de venta`). Sirve para arreglar una terminal sin ir físicamente: resincronizar, rearmar el catálogo, destrabar envíos. **`ComandosCommand::ejecutar()` es la frontera de seguridad**: traduce un identificador de una lista cerrada con un `match` que lanza excepción ante un valor desconocido. Nunca ejecutar texto libre que venga del Manager — eso lo convertiría en ejecución remota arbitraria sobre la caja.
- **Remitos por recibir** (`App\Services\RemitosEntrantesService`): `pos:sync --stock` también baja `GET pos/remitos` y reemplaza la tabla local `remitos_entrantes` (copia descartable, no fuente de verdad). La alerta del header (`EstadoSync`) y la pantalla `/remitos` leen solo esa tabla, sin red. **Recibir sí requiere conexión** (`POST pos/remitos/{id}/recibir`): aplica el stock que devuelve el Manager y **no crea `MovimientoStock` local**, porque se enviaría por `/sync/movimientos` y el Manager sumaría la mercadería dos veces; tampoco suma stock offline, porque el pull de cada minuto se lo pisaría. El endpoint es idempotente (`status` `recibido`/`ya_recibido`), así que se reintenta ante fallas de conexión; 404/409 sacan el remito de la lista. `ManagerApiService::recibirRemito` no usa `client()` porque su `retry()` convierte los 4xx en excepción.
- **El stock del Manager nunca se aplica tal cual**: `syncStock()` (y la recepción de remitos) le suma `MovimientoStock::pendientesPorProducto()`, lo que la caja movió y el Manager todavía no recibió. Sin eso, al volver la conexión el pull de cada minuto podía llegar antes que el push y "devolver" al stock lo vendido offline, permitiendo venderlo de nuevo. Los pendientes se leen **antes** del GET a propósito: ante una carrera, prefiere descontar de más un minuto a vender dos veces.
- **Offline**: `ManagerApiService` usa `connectTimeout(5)` → con el servidor caído una llamada se rinde en ~15s (3 intentos), sin internet en <1s. El header muestra "Sin conexión desde HH:MM" cuando `ultima_sincronizacion_stock` tiene más de 4 minutos, deducido de SQLite, sin red. Simulación completa (vender offline, reconectar en el peor orden) en el historial de la sesión del 2026-09-13; conviene convertirla en test cuando haya PHPUnit.
- **`withoutOverlapping()` siempre con minutos** (`routes/console.php`, lo verifica `TareasProgramadasTest`): el default deja el candado 24 h si el proceso muere a mitad de la tarea. Pasó en la caja 2: se cerró la app durante un `pos:sync --stock` y estuvo 11 horas sin bajar stock, promociones, cajeros ni facturación, mientras `pos:comandos` seguía informando la versión (parecía conectada). La app de escritorio además corre `schedule:clear-cache` al arrancar (`NativeAppServiceProvider::boot`).
- **Panel de salud**: `pos:comandos` manda cada minuto `POST pos/estado` con `EstadoCajaService::reporte()` (último stock y catálogo, ventas/devoluciones sin enviar, facturas pendientes/rechazadas, cola, turno abierto). Sale de `pos:comandos` y no del sync **a propósito**: si el sync se traba, este sigue vivo y el Manager ve el stock viejo. Solo lee SQLite; si falla no frena las órdenes.
- **Stock al día**: `pos:sync --stock` corre **cada minuto**. Trae solo stock (un GET y un update por producto) y por eso puede correr seguido; el pull completo no, porque `syncPrecios()` trunca y reconstruye entera la tabla `precios`. Esto es lo que mantiene el stock de la caja actualizado cuando cambia por fuera: otra caja vendió, llegó un remito.
- **Los procesos de fondo (`queue:work` y `schedule:work`) no arrancan solos.** En cada caja se registran como **tareas programadas de Windows**, llamadas `POS Sync Worker - <nombre>` y `POS Sync Scheduler - <nombre>`. Las crea el paso 9 de `instalar-pos.bat`, o a mano `instalar-inicio-automatico.bat`; se quitan con `desinstalar-inicio-automatico.bat`.
  - Arrancan al iniciar sesión y además tienen un disparador cada 5 minutos con `MultipleInstances: IgnoreNew`, lo que funciona como auto-reparación: si el proceso murió se vuelve a levantar solo, y si sigue vivo el disparo se ignora.
  - Corren vía `ejecutar-oculto.vbs` para que no quede una consola abierta que alguien pueda cerrar sin querer. Ese VBS usa `Run(..., 0, True)` — el `True` (esperar) es lo que mantiene la tarea marcada como "en ejecución"; sin eso, cada disparo de 5 minutos lanzaría una copia nueva.
  - El sufijo con el nombre del POS permite convivir varias instalaciones en la misma máquina.
- `servicios.bat` levanta los dos procesos a mano, sin tareas programadas. Sirve para desarrollo o si el registro falló.
- **Síntoma de que falta el worker**: se acumulan filas en la tabla `jobs` y el indicador del header queda en "N pendientes" hasta que pasa el scheduler de 5 minutos. Diagnóstico: `php artisan queue:monitor database`, `php artisan queue:failed`, `Get-ScheduledTask 'POS Sync *'`.
- El componente `Pos\EstadoSync` (incrustado en `layouts/pos.blade.php`) muestra el estado real en el header: cuántas ventas/movimientos quedan pendientes, y un botón para forzar el envío. Su `wire:poll` solo cuenta contra SQLite local — **no hace llamadas de red**, a propósito, para que un Manager caído no cuelgue la pantalla de venta (`ManagerApiService` usa `timeout(30)->retry(3)`, o sea hasta ~90s de espera). El push por red solo ocurre cuando el usuario aprieta el botón, o desde el scheduler, que corre fuera del proceso web.

### Caja, cobro y cierre Z

- **Sin turno abierto no se vende.** `CajaService` abre (cajero + fondo inicial, un solo turno abierto a la vez), registra movimientos de efectivo (`ingreso`/`retiro`/`gasto`, no se puede retirar más de lo esperado), arma el **informe X** (`resumen()`, en vivo) y hace el **cierre Z** (`cerrar()`: arqueo contra lo contado, congela el resumen en `turnos_caja.resumen` y el turno queda inmutable). El Z se reimprime siempre desde esa foto.
- **`VentaService::registrar()` es el único camino para vender.** Recalcula en el servidor precio (desde la base, `getPrecioEfectivo`), stock, promoción y que la suma de los pagos cubra exacto el total. **Nunca confiar en `$carrito`, `$total` o `$pagos` del componente Livewire**: son propiedades públicas que el navegador puede alterar (hay tests que lo hacen). La pantalla muestra lo que calcula `calcularPago()` / `subtotalCentavos()`, las mismas funciones que registran.
- **Plata en centavos enteros** (`App\Support\Dinero`); se guarda como decimal(12,2). En un pago: `monto` = lo que cubre de la venta, `descuento` = promoción, `importe` = lo cobrado. `ventas.total` = subtotal − descuentos = suma de importes. El vuelto no afecta el efectivo esperado (el pago en efectivo ya es neto).
- **Promociones bancarias**: se definen en el Manager y bajan por `sync/promociones` (en `syncInicial` y cada minuto con `pos:sync --stock`), la tabla local se reemplaza entera. `PromocionBancaria::aplicaA()` (medio, tarjeta, banco normalizado, día, vigencia, mínimo) y `descuentoPara()` (porcentaje con tope; `reintegro` y solo-cuotas no descuentan en caja).
- **Sync**: la venta viaja con `turno_uuid`, `cajero`, cliente y `pagos[]`, en tandas de 100. `pushTurnos()` manda el turno abierto en cada push (el Manager lo ve en vivo) y los cerrados con su Z; un turno se marca `sincronizado` solo cuando el Manager lo confirmó **cerrado** (`cerrado`/`duplicado`). El job de la cola y `pos:sync --push` envían turnos después de las ventas.
- **Propiedades Livewire numéricas van tipadas `string`**, no `string|float`: con tipos unión Livewire deja la propiedad sin inicializar cuando llega un input vacío (`PropertyNotFoundException`).
- Informe imprimible: `/caja/turnos/{turno}/informe` (`InformeCajaController`, X si está abierto, Z si está cerrado), formato 80 mm para la TM-T20 vía diálogo de impresión. La impresión directa ESC/POS es de la etapa 2.

### Cajeros, autorizaciones, devoluciones e impresión (etapa 2)

- **Cajeros con PIN**: bajan del Manager por `sync/cajeros` (en `syncInicial` y cada minuto) con el PIN en bcrypt; se verifican localmente para funcionar offline. `AutorizacionService::verificar()` limita a 5 intentos por minuto por cajero (`RateLimiter`). **Con cajeros cargados la caja solo se abre con `CajaService::abrirConPin()`**; `abrir()` con nombre libre queda para instalaciones sin cajeros y lo bloquea el propio servicio.
- **Supervisor** (`rol = supervisor`) autoriza: descuentos manuales por encima de `descuento_maximo_sin_autorizacion` (Ajustes), devoluciones/anulaciones y guardar Ajustes. El PIN nunca queda en el estado de Livewire (se limpia en `finally`).
- **`#[Locked]`** en `Pos\Venta` para carrito, totales, pagos y la autorización del descuento: el navegador no puede cambiarlos (hay test). `VentaService` igual recalcula y valida `validarDescuentoManual()`.
- **Devoluciones** (`DevolucionService`): siempre con supervisor y turno abierto. Importe = precio de la línea con los descuentos de la venta prorrateados; nunca se devuelve más que lo cobrado y una devolución total cierra exacto. El stock vuelve con `MovimientoStock` tipo `devolucion` (viaja por `/sync/movimientos`); el comprobante viaja por `/sync/devoluciones` **solo cuando su venta ya está sincronizada** (se vincula por `venta_uuid`). Las devoluciones en efectivo descuentan del arqueo del turno en que se hacen.
- **Impresión**: `App\Contracts\ImpresoraTickets` (registrada en `AppServiceProvider`): `ImpresoraNativePHP` imprime silencioso por Electron en la impresora de Ajustes; `ImpresoraNavegador` (instalación clásica) no imprime y la pantalla abre la versión con diálogo. `TicketService` arma ticket, comprobante de devolución e informes X/Z con el mismo HTML para impresora y navegador. **NativePHP arma `data:text/html,${html}` sin codificar: hay que mandar `rawurlencode($html)`** o un `#` corta el ticket. En tests se reemplaza con `$this->app->instance(ImpresoraTickets::class, ...)`.
- **Livewire + DI en `render()`**: no se usa; se resuelve con `app()` (la inyección está garantizada en acciones, no en `render`).

### Facturación electrónica (`App\Services\FacturacionService`)

- **La caja no habla con AFIP**: el Manager tiene el certificado, numera y pide el CAE. `pos:sync --stock` baja `GET pos/facturacion` (emisor + si esta caja factura) a `configuracion.facturacion`; con eso se decide al cobrar, sin red.
- Con facturación activa **toda venta se factura** (consumidor final por defecto). `VentaService::registrar()` valida el cliente con `FacturacionService::receptor()` **antes** de registrar (CUIT módulo 11, CUIT obligatorio si no es consumidor final): una factura A mal cargada la rechazaría AFIP con el cliente ya ido. Guarda `facturar`, `receptor_*` y `comprobante_estado = pendiente`.
- `Pos\Venta::finalizarVenta()` llama a `facturarAhora()` **antes de imprimir**: `POST pos/facturas` con la venta entera (sin reintentos, `connectTimeout(3)`, `timeout(20)`). Si vuelve con CAE, la venta queda sincronizada y el ticket sale como factura. Si no (sin conexión, AFIP caído) la venta **ya está registrada**: queda pendiente con aviso, sale por el push normal con el bloque `factura`, y `actualizarPendientes()` (cada minuto y en el job de la cola) trae el resultado y el de las notas de crédito. Ventas → "Pedir factura ahora" para reintentar.
- La venta guarda en `comprobante` (json) lo que devuelve el Manager, **incluido el QR en SVG**: la factura se reimprime sin conexión. Tickets: `tickets/_fiscal-encabezado` y `_fiscal-pie` (A discrimina IVA por alícuota; B muestra "IVA contenido", Ley 27.743). El SVG se imprime crudo solo si pasa el filtro (sin `<script>`, `on*=`, `foreignObject`).
- Una venta enviada que el Manager no facturó en 15 minutos (facturación desactivada allá) pasa a `rechazado` para no quedar pendiente para siempre.

### Clientes y cuenta corriente (`App\Services\CuentaCorrienteService`)

- `clientes` es una copia del Manager (id del Manager) que `pos:sync --stock` reemplaza entera con el **saldo** de cada uno. Elegir un cliente en la venta hace que nombre, documento y condición de IVA salgan de su ficha (y de ahí la factura).
- **Saldo sin conexión**: saldo del Manager + ventas a cuenta − cobros − créditos de devoluciones que el Manager no incluía al armar la lista: lo no enviado y lo enviado **después** de pedirla. `clientes.sincronizado_at` es la hora de la caja **antes** del GET (`SyncService::syncClientes`), así no depende de que los relojes coincidan. No cambiar a la hora de la respuesta: una venta confirmada durante el pedido quedaría fuera del saldo.
- Medio de pago `cuenta_corriente`: solo con cliente con cuenta habilitada y dentro del límite (`limite_credito` null = sin límite); lo valida `VentaService::registrar()`. Una venta entera a cuenta no admite devolución en efectivo: se acredita (`medio_original`), como mucho lo cargado a cuenta en esa venta — mismo criterio que el Manager.
- Cobro de deuda desde Caja (`CobroCuentaCorriente`, recibo `tickets/cobro`): el efectivo suma al arqueo (`resumen.efectivo.cobros_cuenta_corriente`) y viaja por `/sync/cobros-cuenta-corriente`. Solo se marca enviado lo que el Manager devolvió `creado`/`duplicado`.

### Búsqueda de productos (SQLite FTS5)

`productos` tiene una tabla virtual **FTS5** asociada, `productos_fts` (contenido externo, indexada por `productos.id`), mantenida sincronizada mediante tres triggers `AFTER INSERT/UPDATE/DELETE` creados directamente en la migración `create_productos_table` (no vía Eloquent). `Producto::scopeSearch()` primero intenta un match exacto sobre `codigo_interno`/`codigo_barras`, luego consulta `productos_fts ... MATCH ? ORDER BY rank`, y solo cae a un `LIKE` plano si FTS no devuelve nada. Cualquier migración que toque `productos.busqueda` o la estructura de la tabla debe mantener estos triggers sincronizados o la búsqueda se rompe silenciosamente.

### Precios

El `precio` de un producto es un precio base; `App\Models\ListaPrecio` (lista de precios) aplica un multiplicador (`factor`) plano o un override por producto en `Precio` (con ventana de vigencia opcional `vigencia_desde`/`vigencia_hasta`). `Producto::getPrecioEfectivo(?listaId)` es el único lugar donde se resuelve esto — el override gana sobre el factor, y si no se pasa lista, cae a la lista por defecto (`ListaPrecio::es_default`). Siempre calcular precios a través de este método, no leyendo `productos.precio` directamente.

### Componentes Livewire (`app/Livewire/`)

- `Pos\Venta` — la pantalla principal de venta (ruta `/`). Apertura de caja si no hay turno, carrito, búsqueda (Enter agrega si queda un solo resultado; tipear no agrega) y modal de cobro (F2) con pagos divididos, vuelto y promociones aplicables. Registrar lo hace `VentaService`; después despacha `SincronizarPendientes`.
- `Pos\Caja` — informe X en vivo, movimientos de efectivo, cierre Z con arqueo e historial de cierres.
- `Pos\Productos`, `Pos\Stock`, `Pos\Sincronizacion` — navegación del catálogo, vista de stock, y la UI para disparar la sincronización, respectivamente.
- `Configuracion\Inicial` — configuración inicial: pide la URL del Manager + credenciales del POS, llama a `ManagerApiService::authenticate()`, y luego ofrece "Sincronizar Catálogo Inicial".

Los números de venta se generan como `PDV{punto_de_venta_id}-{id}` vía `Venta::generarNumeroVenta()`, basado en `MAX(id)+1` — no es seguro ante escrituras concurrentes, pero esta app es de una sola instancia por caja, así que es una limitación aceptada, no un bug para "arreglar" sin discutirlo antes.

### Frontend

**Al agregar clases de Tailwind hay que recompilar** (`npm run build`). Tailwind v4 solo genera CSS para las clases que encuentra escaneando los archivos, así que una clase nueva en un Blade no existe hasta el build. El síntoma es confuso: el elemento se renderiza sin estilo, sin ningún error — un botón oscuro que sale blanco, un color que no aparece. Para diagnosticar: `Select-String -Path "public\build\assets\*.css" -Pattern "text-amber-300"`.

Tailwind CSS v4 + Alpine.js, tema oscuro (`bg-slate-900`), compilado con Vite (`resources/css/app.css`, `resources/js/app.js`). Sin librería de componentes más allá de Livewire/Alpine. `layouts/pos.blade.php` es el shell para todas las pantallas del POS ya configurado; `layouts/guest.blade.php` para el flujo previo a la configuración inicial.
