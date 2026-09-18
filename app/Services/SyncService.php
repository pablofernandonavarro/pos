<?php

namespace App\Services;

use App\Models\Cajero;
use App\Models\Cliente;
use App\Models\CobroCuentaCorriente;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\PagoVenta;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\PromocionBancaria;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncService
{
    /** Ventas por request al Manager. */
    private const TANDA_VENTAS = 100;

    /** Productos por página en paginación. */
    private const PRODUCTOS_POR_PAGINA = 500;

    /** Precios por página en paginación. */
    private const PRECIOS_POR_PAGINA = 500;

    /** Filas por upsert en batch. */
    private const FILAS_POR_UPSERT = 100;

    /** Claves de configuración para sync de stock. */
    private const MARCA_STOCK = 'sync_stock_marca';

    private const RECONCILIACION_STOCK = 'sync_stock_reconciliacion';

    private const HORAS_RECONCILIACION_STOCK = 24;

    private const STOCK_POR_PAGINA = 500;

    private const PROGRESO_PRODUCTOS = 'sync_productos_progreso';

    public function __construct(
        private readonly ManagerApiService $managerApi
    ) {}

    /**
     * Sincronización completa inicial (productos, precios, stock).
     *
     * Sin una transacción alrededor: con 200.000 productos la descarga lleva minutos y una
     * transacción abierta durante las llamadas HTTP bloqueaba la base (las ventas daban
     * "database is locked") y ante un corte descartaba todo lo bajado. Cada paso queda
     * consistente por sí solo: productos confirma página por página y retoma donde quedó,
     * precios reemplaza la tabla en una transacción corta al final y el stock es idempotente.
     */
    public function syncInicial(): array
    {
        $resultados = [
            'productos' => ['success' => false, 'cantidad' => 0],
            'precios' => ['success' => false, 'cantidad' => 0],
            'stock' => ['success' => false, 'cantidad' => 0],
        ];

        try {
            // 1. Sincronizar productos
            $productosResult = $this->syncProductos();
            $resultados['productos'] = $productosResult;

            if (! $productosResult['success']) {
                throw new \Exception($productosResult['error'] ?? 'Error sincronizando productos');
            }

            // 2. Sincronizar precios
            $preciosResult = $this->syncPrecios();
            $resultados['precios'] = $preciosResult;

            if (! $preciosResult['success']) {
                throw new \Exception($preciosResult['error'] ?? 'Error sincronizando precios');
            }

            // 3. Sincronizar stock
            $stockResult = $this->syncStock();
            $resultados['stock'] = $stockResult;

            if (! $stockResult['success']) {
                throw new \Exception($stockResult['error'] ?? 'Error sincronizando stock');
            }

            // Sin abortar: sin promociones la caja vende igual, y el pull de cada minuto las
            // vuelve a intentar.
            $resultados['promociones'] = $this->syncPromociones();
            $resultados['cajeros'] = $this->syncCajeros();
            $resultados['facturacion'] = $this->syncFacturacion();
            $resultados['clientes'] = $this->syncClientes();
            $resultados['sucursales'] = $this->syncSucursales();
            $resultados['configuracion_remitos'] = $this->syncConfiguracionRemitos();

            return [
                'success' => true,
                'resultados' => $resultados,
            ];
        } catch (\Exception $e) {
            Log::error('Error en sincronización inicial', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'resultados' => $resultados,
            ];
        }
    }

    /**
     * Sincroniza productos desde el manager: el catálogo entero la primera vez y después solo
     * lo que cambió desde la última (updated_since). Corre cada 5 minutos (`pos:sync
     * --productos`): sin eso un producto dado de alta en el Manager (a mano o por Excel) no
     * llegaba a la caja hasta un "Sincronizar" manual.
     *
     * Por páginas de PRODUCTOS_POR_PAGINA con cursor: nunca se tiene el catálogo entero en
     * memoria ni en una sola respuesta. Cada página se guarda junto con el cursor siguiente
     * en la misma transacción (`sync_productos_progreso`), así un corte (red, app cerrada)
     * retoma desde la última página confirmada. La marca se mueve recién con la última.
     */
    public function syncProductos(): array
    {
        // Scheduler, botón "Sincronizar" y órdenes del Manager pueden coincidir: dos descargas
        // a la vez no rompen nada (todo es upsert por id) pero duplican el trabajo.
        $candado = Cache::lock('pos-sync-productos', 20 * 60);

        if (! $candado->get()) {
            return ['success' => false, 'error' => 'Ya hay una sincronización de productos en curso'];
        }

        try {
            return $this->bajarProductos();
        } finally {
            $candado->release();
        }
    }

    /**
     * @return array{success: bool, cantidad?: int, error?: string}
     */
    private function bajarProductos(): array
    {
        $marca = Configuracion::get('ultima_sincronizacion_productos');
        $progreso = json_decode((string) Configuracion::get(self::PROGRESO_PRODUCTOS), true);

        // Una descarga cortada se retoma solo si es la misma (mismo updated_since).
        if (! is_array($progreso) || ($progreso['desde'] ?? null) !== $marca) {
            $progreso = ['desde' => $marca, 'cursor' => null];

            // Catálogo completo sobre una caja con productos: primero se corrigen ids corridos.
            // Solo al empezar: al retomar ya se hizo.
            if ($marca === null && Producto::query()->exists()) {
                $realineo = $this->realinearContraCatalogoCompleto();

                if (! $realineo['success']) {
                    return $realineo;
                }
            }
        }

        $pedidoAt = now();
        $sincronizados = 0;

        do {
            $respuesta = $this->managerApi->pagina('productos', [
                'limit' => self::PRODUCTOS_POR_PAGINA,
                'cursor' => $progreso['cursor'],
                'updated_since' => $progreso['desde'],
                'incluir_inactivos' => 1,
            ]);

            if (! $respuesta['success']) {
                // Cursor rechazado: la próxima corrida empieza de nuevo en vez de insistir.
                if (($respuesta['status'] ?? null) === 422 && $progreso['cursor'] !== null) {
                    Configuracion::set(self::PROGRESO_PRODUCTOS, null);
                }

                return $respuesta;
            }

            $json = $respuesta['json'];
            // Un Manager anterior a la paginación manda todo junto y sin next_cursor.
            $legado = ! array_key_exists('next_cursor', $json);
            $siguiente = $legado ? null : $json['next_cursor'];

            DB::transaction(function () use ($json, $legado, $siguiente, $progreso, $pedidoAt, &$sincronizados) {
                $sincronizados += $this->aplicarPaginaDeProductos($json['data'] ?? []);

                if ($siguiente) {
                    Configuracion::set(self::PROGRESO_PRODUCTOS, json_encode([...$progreso, 'cursor' => $siguiente]));

                    return;
                }

                Configuracion::set(self::PROGRESO_PRODUCTOS, null);
                Configuracion::set('ultima_sincronizacion_productos', $this->marcaDeProductos($json, $legado, $pedidoAt));
            });

            $progreso['cursor'] = $siguiente;
        } while ($siguiente);

        return [
            'success' => true,
            'cantidad' => $sincronizados,
        ];
    }

    /**
     * La marca se compara con el updated_at del Manager, así que se usa su reloj (synced_at)
     * y no el de la caja. El Manager ya le resta 15 minutos de margen (transacciones largas);
     * uno anterior no, y ahí se restan 2 minutos de solape como antes. Volver a bajar un par
     * de productos no cambia nada.
     *
     * @param  array<string, mixed>  $json
     */
    private function marcaDeProductos(array $json, bool $legado, Carbon $pedidoAt): string
    {
        $marca = ! empty($json['synced_at']) ? Carbon::parse($json['synced_at']) : $pedidoAt->copy();

        return ($legado ? $marca->subMinutes(2) : $marca)->toIso8601String();
    }

    /**
     * Upsert de una página por el id del Manager: es el que viaja en las ventas y el que usa
     * el stock (con updateOrCreate() el id, que no es fillable, se descartaba en silencio y
     * SQLite asignaba su propio autoincremental). Un solo INSERT … ON CONFLICT por tanda en
     * vez de un find + save por producto; los triggers de productos_fts corren igual.
     *
     * El stock de un producto existente no se toca (lo mueven las ventas y syncStock); uno
     * nuevo entra con el de su sucursal más lo movido offline, así un catálogo rearmado no
     * queda en cero mientras el delta de stock no lo trae.
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function aplicarPaginaDeProductos(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        $ids = array_map(fn (array $p) => (int) $p['id'], $filas);
        $existentes = array_flip(DB::table('productos')->whereIn('id', $ids)->pluck('id')->all());
        $pendientes = MovimientoStock::pendientesPorProducto(array_values(array_diff($ids, array_keys($existentes))));
        $ahora = now()->toDateTimeString();
        $registros = [];

        foreach ($filas as $p) {
            $id = (int) $p['id'];
            $activo = (bool) ($p['activo'] ?? true);

            // La baja de un producto que esta caja nunca tuvo no tiene nada que desactivar.
            if (! $activo && ! isset($existentes[$id])) {
                continue;
            }

            $registros[$id] = [
                'id' => $id,
                'nombre' => $p['nombre'],
                'codigo_interno' => $p['codigo_interno'] ?? null,
                'codigo_barras' => $p['codigo_barras'] ?? null,
                'busqueda' => $p['busqueda'] ?? $p['nombre'],
                'precio' => $p['precio'] ?? 0,
                'costo' => $p['costo'] ?? 0,
                'stock' => (int) ($p['stock'] ?? 0) + ($pendientes[$id] ?? 0),
                'stock_critico' => $p['stock_critico'] ?? 0,
                'imagen_url' => $p['imagen_url'] ?? null,
                'descripcion_web' => $p['descripcion_web'] ?? null,
                'marca' => $p['marca'] ?? null,
                'color' => $p['color'] ?? null,
                'n_talle' => $p['n_talle'] ?? null,
                'genero' => $p['genero'] ?? null,
                'n_grupo' => $p['n_grupo'] ?? null,
                'n_subgrupo' => $p['n_subgrupo'] ?? null,
                'n_temporada' => $p['n_temporada'] ?? null,
                'product_type' => $p['product_type'] ?? 'simple',
                'parent_id' => $p['parent_id'] ?? null,
                'modelo_codigo' => $p['parent_codigo_interno'] ?? null,
                'modelo_nombre' => $p['parent_nombre'] ?? null,
                'es_vendible' => (bool) ($p['es_vendible'] ?? true),
                'activo' => $activo,
                'sincronizado_at' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        $actualizar = array_values(array_diff(array_keys(reset($registros) ?: []), ['id', 'stock', 'created_at']));

        foreach (array_chunk(array_values($registros), self::FILAS_POR_UPSERT) as $tanda) {
            DB::table('productos')->upsert($tanda, ['id'], $actualizar);
        }

        return count($registros);
    }

    /**
     * Baja el catálogo completo solo para emparejar ids (ver realinearIdsDeProductos). De cada
     * producto guarda la firma y el código, no el producto entero: con 200.000 son unos
     * 40 MB en vez de cientos. Pasa una sola vez en la vida de una caja vieja.
     *
     * @return array{success: bool, error?: string}
     */
    private function realinearContraCatalogoCompleto(): array
    {
        $firmas = [];
        $codigos = [];
        $cursor = null;

        do {
            $respuesta = $this->managerApi->pagina('productos', ['limit' => self::PRODUCTOS_POR_PAGINA, 'cursor' => $cursor]);

            if (! $respuesta['success']) {
                return $respuesta;
            }

            foreach ($respuesta['json']['data'] ?? [] as $p) {
                $firmas[(int) $p['id']] = self::firmaDeProducto($p);
                $codigos[(int) $p['id']] = trim((string) ($p['codigo_interno'] ?? ''));
            }

            $cursor = $respuesta['json']['next_cursor'] ?? null;
        } while ($cursor);

        $this->realinearIdsDeProductos($firmas, $codigos);

        return ['success' => true];
    }

    /**
     * Corrige cajas cuyo catálogo quedó con ids propios en vez de los del Manager.
     *
     * Hasta que syncProductos forzó el id, SQLite numeraba seguido. El Manager no manda
     * los productos padre de los configurables, así que en su numeración hay huecos y
     * desde el primero todo quedaba corrido: la caja mandaba ventas con un id que en el
     * Manager es otro artículo, y le descontaba stock a ese.
     *
     * El emparejamiento no puede ser solo por código: las variantes de un configurable
     * comparten código y, en muchos casos, todos los datos. Se agrupa por los datos que
     * identifican al producto (la "firma") y se resuelve en este orden:
     *   1. Si el producto local ya tiene el id del Manager y la misma firma, se respeta.
     *   2. Dentro de cada firma, por orden: el pull original insertó en el orden en que
     *      venían del Manager, que es el orden de id.
     *   3. Lo que sigue sin par, por código, solo si es inequívoco de los dos lados
     *      (productos editados en el Manager desde el último pull).
     * Lo que sobra después de eso:
     *   - Duplicados (el bug también creaba filas repetidas): sus ventas pasan al producto
     *     con la misma firma y la fila se borra.
     *   - Productos que el Manager ya no manda: quedan inactivos, con su historial.
     *
     * @param  array<int, string>  $firmaManager  id del Manager => firma
     * @param  array<int, string>  $codigoDe  id del Manager => código interno
     */
    private function realinearIdsDeProductos(array $firmaManager, array $codigoDe): void
    {
        ksort($firmaManager);
        $gruposManager = [];

        foreach ($firmaManager as $id => $firma) {
            $gruposManager[$firma][] = $id;
        }

        $locales = Producto::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Producto $p) => [$p->id => self::firmaDeProducto($p->getAttributes())])
            ->all();

        $destinos = []; // id local => id del Manager

        // 1. Ya correctos
        foreach ($locales as $id => $firma) {
            if (($firmaManager[$id] ?? null) === $firma) {
                $destinos[$id] = $id;
            }
        }

        // 2. Por orden dentro de cada firma
        $libresManager = array_diff_key($firmaManager, array_flip($destinos));

        foreach (array_diff_key($locales, $destinos) as $id => $firma) {
            $candidato = array_search($firma, $libresManager, true);

            if ($candidato !== false) {
                $destinos[$id] = $candidato;
                unset($libresManager[$candidato]);
            }
        }

        // 3. Por código inequívoco
        $libresPorCodigo = collect($libresManager)->keys()->groupBy(fn (int $id) => $codigoDe[$id])
            ->filter(fn ($ids, $codigo) => $codigo !== '' && $ids->count() === 1);
        $sinParPorCodigo = Producto::whereKey(array_keys(array_diff_key($locales, $destinos)))->get()
            ->groupBy(fn (Producto $p) => trim((string) $p->codigo_interno))
            ->filter(fn ($productos) => $productos->count() === 1);

        foreach ($sinParPorCodigo as $codigo => $productos) {
            if ($libresPorCodigo->has($codigo)) {
                $destinos[$productos->first()->id] = $libresPorCodigo->get($codigo)->first();
            }
        }

        $duplicados = []; // id local => id del Manager al que se fusiona
        $huerfanos = [];

        foreach (array_diff_key($locales, $destinos) as $id => $firma) {
            if (isset($gruposManager[$firma])) {
                $duplicados[$id] = $gruposManager[$firma][0];
            } else {
                $huerfanos[] = $id;
            }
        }

        $cambios = array_filter($destinos, fn (int $destino, int $origen) => $destino !== $origen, ARRAY_FILTER_USE_BOTH);

        $huerfanos = Producto::whereKey($huerfanos)->where('activo', true)->pluck('id')->all();

        if ($cambios === [] && $duplicados === [] && $huerfanos === []) {
            return;
        }

        // Por encima de cualquier id local o del Manager: ahí no choca con ningún destino.
        $siguienteLibre = max([...array_keys($locales), ...array_keys($firmaManager)]) + 1;

        DB::transaction(function () use ($cambios, $duplicados, $huerfanos, $siguienteLibre) {
            // Durante el cambio las ventas apuntan por un momento a ids que no existen.
            // defer_foreign_keys posterga el chequeo al commit, donde ya todo cierra.
            DB::statement('PRAGMA defer_foreign_keys = ON');

            // Los duplicados se borran primero y sus referencias quedan estacionadas en un
            // id negativo propio, para no mezclarse con las de quien ocupe su id después.
            foreach ($duplicados as $id => $destino) {
                DB::table('productos')->where('id', $id)->delete();
                $this->moverReferencias($id, -$id);
            }

            // Un huérfano que ocupa un id que otro necesita se corre al final.
            foreach (array_intersect($huerfanos, $cambios) as $id) {
                $this->moverProducto($id, $siguienteLibre);
                $huerfanos[array_search($id, $huerfanos, true)] = $siguienteLibre++;
            }

            // Dos fases para que un intercambio (A→B y B→A) no choque en la clave primaria.
            foreach ($cambios as $origen => $destino) {
                $this->moverProducto($origen, -$origen);
            }

            foreach ($cambios as $origen => $destino) {
                $this->moverProducto(-$origen, $destino);
            }

            foreach ($duplicados as $id => $destino) {
                $this->moverReferencias(-$id, $destino);
            }

            DB::table('productos')->whereIn('id', $huerfanos)->update(['activo' => false]);
        });

        Log::warning('Catálogo realineado con los ids del Manager', [
            'movidos' => count($cambios),
            'duplicados_fusionados' => count($duplicados),
            'inactivados' => count($huerfanos),
        ]);
    }

    private function moverProducto(int $de, int $a): void
    {
        DB::table('productos')->where('id', $de)->update(['id' => $a]);
        $this->moverReferencias($de, $a);
    }

    private function moverReferencias(int $de, int $a): void
    {
        DB::table('detalle_ventas')->where('product_id', $de)->update(['product_id' => $a]);
        DB::table('movimientos_stock')->where('product_id', $de)->update(['product_id' => $a]);
        DB::table('precios')->where('product_id', $de)->update(['product_id' => $a]);
    }

    /**
     * @param  array<string, mixed>  $producto
     */
    private static function firmaDeProducto(array $producto): string
    {
        return implode('|', array_map(
            fn (string $campo) => trim((string) ($producto[$campo] ?? '')),
            ['codigo_interno', 'codigo_barras', 'nombre', 'n_talle', 'color', 'product_type']
        ));
    }

    /**
     * Sincroniza precios y listas desde el manager.
     *
     * Se reemplaza la tabla entera (un precio borrado en el Manager no deja rastro para un
     * delta), pero sin dejar la caja sin precios mientras baja: las páginas van a una tabla
     * temporal y el cambio es una transacción corta al final. Si la descarga se corta, la
     * tabla `precios` queda como estaba.
     */
    public function syncPrecios(): array
    {
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS precios_descarga (lista_precio_id INTEGER NOT NULL, product_id INTEGER NOT NULL, precio_override NUMERIC NOT NULL, vigencia_desde TEXT NULL, vigencia_hasta TEXT NULL)');
        DB::table('precios_descarga')->delete();

        try {
            $listas = null;
            $cursor = null;

            do {
                $respuesta = $this->managerApi->pagina('precios', ['limit' => self::PRECIOS_POR_PAGINA, 'cursor' => $cursor]);

                if (! $respuesta['success']) {
                    return $respuesta;
                }

                $json = $respuesta['json'];
                $listas ??= $json['listas'] ?? [];
                $cursor = $json['next_cursor'] ?? null;

                foreach (array_chunk($json['precios'] ?? [], self::FILAS_POR_UPSERT) as $tanda) {
                    DB::table('precios_descarga')->insert(array_map(fn (array $p) => [
                        'lista_precio_id' => $p['lista_precio_id'],
                        'product_id' => $p['product_id'],
                        'precio_override' => $p['precio_override'],
                        'vigencia_desde' => $p['vigencia_desde'] ?? null,
                        'vigencia_hasta' => $p['vigencia_hasta'] ?? null,
                    ], $tanda));
                }
            } while ($cursor);

            $sincronizados = DB::transaction(function () use ($listas) {
                foreach ($listas as $listaData) {
                    ListaPrecio::updateOrCreate(
                        ['id' => $listaData['id']],
                        [
                            'nombre' => $listaData['nombre'],
                            'factor' => $listaData['factor'],
                            'es_default' => $listaData['es_default'],
                            'sincronizado_at' => now(),
                        ]
                    );
                }

                $this->limpiarListasObsoletas(collect($listas)->pluck('id')->all());

                Precio::query()->delete();

                // Solo precios de productos y listas que la caja tiene: por las foreign keys,
                // antes un precio de un producto que no bajó hacía fallar la sincronización.
                $ahora = now()->toDateTimeString();

                return DB::affectingStatement(
                    'insert into precios (lista_precio_id, product_id, precio_override, vigencia_desde, vigencia_hasta, sincronizado_at, created_at, updated_at)
                     select d.lista_precio_id, d.product_id, d.precio_override, d.vigencia_desde, d.vigencia_hasta, ?, ?, ?
                     from precios_descarga d
                     where exists (select 1 from productos p where p.id = d.product_id)
                       and exists (select 1 from listas_precios l where l.id = d.lista_precio_id)',
                    [$ahora, $ahora, $ahora]
                );
            });
        } finally {
            DB::statement('DROP TABLE IF EXISTS temp.precios_descarga');
        }

        Configuracion::set('ultima_sincronizacion_precios', now()->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
            'listas' => count($listas),
        ];
    }

    /**
     * Borra las listas de precios que el Manager dejó de asignar a esta sucursal.
     *
     * Sin esto quedaban listas viejas conviviendo con las vigentes, y varias con
     * es_default = true. Como ListaPrecio::getDefault() resuelve con un first(), la caja
     * podía terminar cobrando con el factor de una lista que ya no corresponde.
     *
     * Una lista referenciada por una venta no se borra: se conserva por trazabilidad
     * (y la foreign key lo impediría igual), pero se le saca el flag de default para que
     * no pueda volver a elegirse.
     *
     * @param  array<int, int>  $idsVigentes
     */
    private function limpiarListasObsoletas(array $idsVigentes): void
    {
        $obsoletas = ListaPrecio::query()
            ->when($idsVigentes !== [], fn ($q) => $q->whereNotIn('id', $idsVigentes))
            ->get();

        foreach ($obsoletas as $lista) {
            if (Venta::where('lista_precio_id', $lista->id)->exists()) {
                $lista->update(['es_default' => false]);

                continue;
            }

            $lista->delete();
        }
    }

    /**
     * Sincroniza stock desde el manager.
     */
    public function syncStock(): array
    {
        // Sin marca de catálogo hay que bajarlo entero antes: el stock se asigna por id y
        // con ids corridos se le pondría a cada producto el stock de otro. Esto es lo que
        // realinea sola a una caja actualizada (la migración borra la marca), sin
        // esperar a que alguien mande "resincronizar" desde el Manager.
        if ($this->catalogoPendienteDeVerificar()) {
            $productos = $this->syncProductos();

            if (! $productos['success']) {
                return $productos;
            }
        }

        // Se lee ANTES de pedir el stock. Si una venta se envía justo en el medio, la cuenta
        // la descuenta dos veces por un minuto (vender de menos, se corrige solo en el pull
        // siguiente); al revés la devolvería al stock y se podría vender dos veces.
        $pendientes = MovimientoStock::pendientesPorProducto();

        // Delta: solo las filas de stock que cambiaron desde la marca (otra caja vendió, llegó
        // un remito). Una vez por día, o sin marca, se reconcilia todo: cubre lo que un delta
        // no ve (una fila borrada, una caja restaurada de un backup).
        $marca = Configuracion::get(self::MARCA_STOCK);
        $reconciliada = Configuracion::get(self::RECONCILIACION_STOCK);
        $completa = $marca === null || $reconciliada === null
            || Carbon::parse($reconciliada)->lt(now()->subHours(self::HORAS_RECONCILIACION_STOCK));
        $iniciadaAt = now();
        $cursor = null;
        $actualizados = 0;

        do {
            $respuesta = $this->managerApi->pagina('stock', [
                'limit' => self::STOCK_POR_PAGINA,
                'cursor' => $cursor,
                'updated_since' => $completa ? null : $marca,
            ]);

            if (! $respuesta['success']) {
                return $respuesta;
            }

            $json = $respuesta['json'];
            // Un Manager anterior a la paginación manda el stock entero y sin next_cursor.
            $legado = ! array_key_exists('next_cursor', $json);
            $cursor = $legado ? null : $json['next_cursor'];

            $actualizados += $this->aplicarPaginaDeStock($json['data'] ?? [], $pendientes);
        } while ($cursor);

        Configuracion::set('ultima_sincronizacion_stock', now()->toIso8601String());

        // Con un Manager viejo no hay marca: cada corrida trae todo, como antes.
        if (! $legado && ! empty($json['synced_at'])) {
            Configuracion::set(self::MARCA_STOCK, $json['synced_at']);

            if ($completa) {
                Configuracion::set(self::RECONCILIACION_STOCK, $iniciadaAt->toIso8601String());
            }
        }

        return [
            'success' => true,
            'cantidad' => $actualizados,
        ];
    }

    /**
     * Stock de la caja = el del Manager + lo movido acá que el Manager todavía no recibió.
     *
     * Los pendientes se vuelven a leer dentro de la transacción y se usa el menor de los dos:
     * si se envió una venta mientras bajaba la página, se descuenta dos veces un minuto (la
     * fila del Manager cambió, así que el delta siguiente la trae y corrige); si se vendió
     * mientras tanto, esa venta no se pierde. Nunca queda stock de más.
     *
     * Solo se escriben los productos cuyo stock cambió: la reconciliación diaria de 200.000
     * filas escribe unas pocas.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<int, int>  $pendientesAntes
     */
    private function aplicarPaginaDeStock(array $filas, array $pendientesAntes): int
    {
        if ($filas === []) {
            return 0;
        }

        return DB::transaction(function () use ($filas, $pendientesAntes) {
            $pendientesAhora = MovimientoStock::pendientesPorProducto();
            $ahora = now()->toDateTimeString();
            $actualizados = 0;

            foreach ($filas as $fila) {
                $id = (int) $fila['product_id'];
                $stock = (int) $fila['cantidad'] + min($pendientesAntes[$id] ?? 0, $pendientesAhora[$id] ?? 0);

                $actualizados += DB::update(
                    'update productos set stock = ?, updated_at = ? where id = ? and stock <> ?',
                    [$stock, $ahora, $id, $stock]
                );
            }

            return $actualizados;
        });
    }

    /**
     * Envía ventas pendientes al manager.
     */
    public function pushVentas(): array
    {
        if ($this->catalogoPendienteDeVerificar()) {
            return self::esperandoCatalogo();
        }

        $sincronizadas = 0;
        $message = 'No hay ventas pendientes de sincronizar';
        $ultimoId = 0;

        // En tandas: después de un día sin conexión puede haber cientos de ventas, y un solo
        // POST gigante se corta o supera los límites del servidor. El cursor por id evita
        // quedar en loop si el Manager no confirma alguna.
        while (true) {
            $ventas = Venta::with(['detalles', 'pagos', 'turno'])
                ->pendientes()
                ->where('id', '>', $ultimoId)
                ->orderBy('id')
                ->limit(self::TANDA_VENTAS)
                ->get();

            if ($ventas->isEmpty()) {
                break;
            }

            $ultimoId = $ventas->last()->id;

            $response = $this->managerApi->pushVentas($ventas->map(fn (Venta $venta) => $this->payloadVenta($venta))->all());

            if (! $response['success']) {
                return $response;
            }

            $message = $response['message'];

            // Solo se marcan las que el Manager confirmó por uuid (creadas o ya existentes).
            // Lo que no venga en el ack queda pendiente y se reintenta sin riesgo de duplicar.
            $confirmados = collect($response['resultados'] ?? [])->pluck('uuid')->all();

            foreach ($ventas as $venta) {
                if (! in_array($venta->uuid, $confirmados, true)) {
                    continue;
                }

                $venta->marcarSincronizada();
                $this->marcarMovimientosDeVentaSincronizados($venta);
                $sincronizadas++;
            }

            if ($ventas->count() < self::TANDA_VENTAS) {
                break;
            }
        }

        return [
            'success' => true,
            'cantidad' => $sincronizadas,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadVenta(Venta $venta): array
    {
        $venta->loadMissing(['detalles', 'pagos', 'turno']);

        return [
            // Solo si la venta se marcó para facturar: sin este bloque el Manager no factura.
            ...($venta->facturar ? ['factura' => [
                'condicion_iva' => $venta->receptor_condicion_iva ?? 5,
                'doc_tipo' => $venta->receptor_doc_tipo ?? 99,
                'doc_nro' => $venta->receptor_doc_nro,
                'nombre' => $venta->cliente_nombre,
            ]] : []),
            'uuid' => $venta->uuid,
            'lista_precio_id' => $venta->lista_precio_id,
            'turno_uuid' => $venta->turno?->uuid,
            'cajero' => $venta->cajero,
            'vendedor_id' => $venta->vendedor_id,
            'vendedor_nombre' => $venta->vendedor_nombre,
            'numero_venta' => $venta->numero_venta,
            'fecha' => $venta->fecha->toIso8601String(),
            'subtotal' => $venta->subtotal,
            'descuento' => $venta->descuento,
            'descuento_manual' => $venta->descuento_manual,
            'descuento_autorizado_por' => $venta->descuento_autorizado_por,
            'total' => $venta->total,
            'metodo_pago' => $venta->metodo_pago,
            'cliente_nombre' => $venta->cliente_nombre,
            'cliente_documento' => $venta->cliente_documento,
            'cliente_id' => $venta->cliente_id,
            'items' => $venta->detalles->map(fn ($detalle) => [
                'product_id' => $detalle->product_id,
                'cantidad' => $detalle->cantidad,
                'precio_unitario' => $detalle->precio_unitario,
                'subtotal' => $detalle->subtotal,
            ])->all(),
            'pagos' => $venta->pagos->map(fn (PagoVenta $pago) => [
                'medio' => $pago->medio,
                'monto' => $pago->monto,
                'descuento' => $pago->descuento,
                'importe' => $pago->importe,
                'tarjeta' => $pago->tarjeta,
                'banco' => $pago->banco,
                'cuotas' => $pago->cuotas,
                'promocion_id' => $pago->promocion_id,
                'promocion_nombre' => $pago->promocion_nombre,
                'referencia' => $pago->referencia,
            ])->all(),
        ];
    }

    /**
     * Envía los turnos de caja: el abierto (para que el Manager vea la caja en vivo) y los
     * cerrados que todavía no confirmó. Un turno se marca sincronizado recién cuando el
     * Manager lo recibió cerrado; el abierto se reenvía en cada push.
     */
    public function pushTurnos(): array
    {
        $turnos = TurnoCaja::with('movimientos')->pendientes()->orderBy('id')->limit(50)->get();

        if ($turnos->isEmpty()) {
            return ['success' => true, 'cantidad' => 0];
        }

        $caja = app(CajaService::class);

        $payload = $turnos->map(function (TurnoCaja $turno) use ($caja) {
            // El abierto se resume en el momento; el cerrado manda la foto guardada del Z.
            $resumen = $turno->estaAbierto() ? $caja->resumen($turno) : $turno->resumen;

            return [
                'uuid' => $turno->uuid,
                'numero' => $turno->numero,
                'cajero' => $turno->cajero,
                'estado' => $turno->estaAbierto() ? 'abierto' : 'cerrado',
                'fondo_inicial' => $turno->fondo_inicial,
                'abierto_at' => $turno->abierto_at->toIso8601String(),
                'cerrado_at' => $turno->cerrado_at?->toIso8601String(),
                'cantidad_ventas' => $resumen['ventas']['cantidad'],
                'total_ventas' => $resumen['ventas']['total'],
                'efectivo_esperado' => $resumen['efectivo']['esperado'],
                'efectivo_contado' => $turno->efectivo_contado,
                'diferencia' => $resumen['efectivo']['diferencia'] ?? null,
                'resumen' => $resumen,
                'observaciones' => $turno->observaciones,
                'movimientos' => $turno->movimientos->map(fn ($m) => [
                    'uuid' => $m->uuid,
                    'tipo' => $m->tipo,
                    'monto' => $m->monto,
                    'motivo' => $m->motivo,
                    'fecha' => $m->created_at->toIso8601String(),
                ])->all(),
            ];
        })->all();

        $response = $this->managerApi->pushTurnos($payload);

        if (! $response['success']) {
            return $response;
        }

        $estados = collect($response['resultados'] ?? [])->pluck('status', 'uuid');
        $cerrados = 0;

        foreach ($turnos as $turno) {
            // "duplicado" = el Manager ya lo tenía cerrado (se perdió la respuesta antes).
            if (! $turno->estaAbierto() && in_array($estados[$turno->uuid] ?? null, ['cerrado', 'duplicado'], true)) {
                $turno->update(['sincronizado' => true, 'sincronizado_at' => now()]);
                $cerrados++;
            }
        }

        return ['success' => true, 'cantidad' => $cerrados];
    }

    /**
     * Envía los comprobantes de devolución. El stock ya viajó (o viajará) por
     * /sync/movimientos como tipo `devolucion`; esto no toca stock en el Manager.
     */
    public function pushDevoluciones(): array
    {
        // Solo las de ventas que el Manager ya tiene: la devolución se vincula a la venta por
        // uuid, y la venta sale en el push anterior.
        $devoluciones = Devolucion::with(['items', 'venta', 'turno'])
            ->pendientes()
            ->whereHas('venta', fn ($q) => $q->where('sincronizado', true))
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($devoluciones->isEmpty()) {
            return ['success' => true, 'cantidad' => 0];
        }

        $response = $this->managerApi->pushDevoluciones($devoluciones->map(fn (Devolucion $d) => [
            'uuid' => $d->uuid,
            'venta_uuid' => $d->venta->uuid,
            'turno_uuid' => $d->turno?->uuid,
            'numero' => $d->numero,
            'tipo' => $d->tipo,
            'motivo' => $d->motivo,
            'reintegro' => $d->reintegro,
            'total' => $d->total,
            'autorizado_por' => $d->autorizado_por,
            'fecha' => $d->created_at->toIso8601String(),
            'items' => $d->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'cantidad' => $i->cantidad,
                'importe' => $i->importe,
            ])->all(),
        ])->all());

        if (! $response['success']) {
            return $response;
        }

        $confirmados = collect($response['resultados'] ?? [])->pluck('uuid')->all();
        $marcadas = 0;

        foreach ($devoluciones as $devolucion) {
            if (in_array($devolucion->uuid, $confirmados, true)) {
                $devolucion->update(['sincronizado' => true, 'sincronizado_at' => now()]);
                $marcadas++;
            }
        }

        return ['success' => true, 'cantidad' => $marcadas];
    }

    /**
     * Reemplaza la copia local de cajeros. Si el Manager no manda ninguno, la tabla queda
     * vacía y la caja vuelve a abrirse con nombre libre.
     */
    public function syncCajeros(): array
    {
        $response = $this->managerApi->syncCajeros();

        if (! $response['success']) {
            return $response;
        }

        $cajeros = collect($response['data']);

        DB::transaction(function () use ($cajeros) {
            Cajero::whereNotIn('id', $cajeros->pluck('id'))->delete();

            foreach ($cajeros as $c) {
                Cajero::updateOrCreate(['id' => $c['id']], [
                    'nombre' => $c['nombre'],
                    'rol' => $c['rol'],
                    'pin_hash' => $c['pin_hash'],
                    'foto_url' => $c['foto_url'] ?? null,
                ]);
            }
        });

        return ['success' => true, 'cantidad' => $cajeros->count()];
    }

    /**
     * Reemplaza la copia local de clientes. `sincronizado_at` es la hora de la caja **antes**
     * de pedir: lo que la caja envió después puede no estar en el saldo que devuelve el
     * Manager, y CuentaCorrienteService lo suma aparte.
     */
    public function syncClientes(): array
    {
        $pedidoAt = now();
        $response = $this->managerApi->syncClientes();

        if (! $response['success']) {
            return $response;
        }

        $clientes = collect($response['data']);

        DB::transaction(function () use ($clientes, $pedidoAt) {
            Cliente::whereNotIn('id', $clientes->pluck('id'))->delete();

            foreach ($clientes as $c) {
                Cliente::updateOrCreate(['id' => $c['id']], [
                    'nombre' => $c['nombre'],
                    'doc_tipo' => $c['doc_tipo'] ?? 99,
                    'documento' => $c['documento'] ?? null,
                    'condicion_iva' => $c['condicion_iva'] ?? 5,
                    'telefono' => $c['telefono'] ?? null,
                    'email' => $c['email'] ?? null,
                    'cuenta_corriente' => (bool) ($c['cuenta_corriente'] ?? false),
                    'limite_credito' => $c['limite_credito'] ?? null,
                    'saldo' => $c['saldo'] ?? 0,
                    'sincronizado_at' => $pedidoAt,
                ]);
            }
        });

        return ['success' => true, 'cantidad' => $clientes->count()];
    }

    /**
     * Envía los cobros de cuenta corriente pendientes. Idempotente por uuid.
     */
    public function pushCobrosCuentaCorriente(): array
    {
        $cobros = CobroCuentaCorriente::pendientes()->orderBy('id')->limit(200)->get();

        if ($cobros->isEmpty()) {
            return ['success' => true, 'cantidad' => 0];
        }

        $response = $this->managerApi->pushCobrosCuentaCorriente($cobros->map(fn (CobroCuentaCorriente $c) => [
            'uuid' => $c->uuid,
            'cliente_id' => $c->cliente_id,
            'importe' => $c->importe,
            'medio' => $c->medio,
            'fecha' => $c->created_at->toIso8601String(),
            'cajero' => $c->cajero,
            'numero' => $c->numero,
        ])->all());

        if (! $response['success']) {
            return $response;
        }

        $confirmados = collect($response['resultados'])
            ->whereIn('status', ['creado', 'duplicado'])
            ->pluck('uuid')->all();

        CobroCuentaCorriente::whereIn('uuid', $confirmados)->update(['sincronizado' => true, 'sincronizado_at' => now()]);

        return ['success' => true, 'cantidad' => count($confirmados)];
    }

    /**
     * Datos del emisor y si esta caja factura. Se guardan para imprimir la factura y decidir
     * al cobrar sin depender de la red. Sin conexión queda lo último que se supo.
     */
    public function syncFacturacion(): array
    {
        $response = $this->managerApi->emisorFacturacion();

        if (! $response['success']) {
            return $response;
        }

        Configuracion::set('facturacion', json_encode($response['data']));

        return ['success' => true, 'activa' => (bool) ($response['data']['activa'] ?? false)];
    }

    /**
     * Reemplaza la copia local de promociones bancarias por las que asigna el Manager.
     */
    public function syncPromociones(): array
    {
        $response = $this->managerApi->syncPromociones();

        if (! $response['success']) {
            return $response;
        }

        $promociones = collect($response['data']);

        DB::transaction(function () use ($promociones) {
            PromocionBancaria::whereNotIn('id', $promociones->pluck('id'))->delete();

            foreach ($promociones as $p) {
                PromocionBancaria::updateOrCreate(['id' => $p['id']], [
                    'nombre' => $p['nombre'],
                    'banco' => $p['banco'] ?? null,
                    'medios' => $p['medios'] ?? [],
                    'tarjetas' => $p['tarjetas'] ?? null,
                    'dias_semana' => $p['dias_semana'] ?? null,
                    'vigencia_desde' => $p['vigencia_desde'] ?? null,
                    'vigencia_hasta' => $p['vigencia_hasta'] ?? null,
                    'modalidad' => $p['modalidad'] ?? 'descuento',
                    'porcentaje' => $p['porcentaje'] ?? 0,
                    'tope' => $p['tope'] ?? null,
                    'monto_minimo' => $p['monto_minimo'] ?? null,
                    'cuotas_sin_interes' => $p['cuotas_sin_interes'] ?? null,
                ]);
            }
        });

        return ['success' => true, 'cantidad' => $promociones->count()];
    }

    /**
     * Reemplaza la copia local de sucursales.
     */
    public function syncSucursales(): array
    {
        $response = $this->managerApi->obtenerSucursales();

        if (! $response['success']) {
            return $response;
        }

        $sucursales = collect($response['data']);

        DB::transaction(function () use ($sucursales) {
            Sucursal::whereNotIn('id', $sucursales->pluck('id'))->delete();

            foreach ($sucursales as $s) {
                Sucursal::updateOrCreate(['id' => $s['id']], [
                    'nombre' => $s['nombre'],
                    'is_central' => (bool) ($s['is_central'] ?? false),
                ]);
            }
        });

        return ['success' => true, 'cantidad' => $sucursales->count()];
    }

    /**
     * Sincroniza la configuración de remitos (ruta_directa y destino_rechazados).
     */
    public function syncConfiguracionRemitos(): array
    {
        $response = $this->managerApi->obtenerConfiguracionRemitos();

        if (! $response['success']) {
            return $response;
        }

        Configuracion::set('ruta_directa', (bool) ($response['data']['ruta_directa'] ?? true));
        Configuracion::set('destino_rechazados', $response['data']['destino_rechazados'] ?? 'origen');

        return ['success' => true];
    }

    /**
     * Envía movimientos de stock pendientes al manager.
     */
    public function pushMovimientos(): array
    {
        // Los movimientos de tipo 'venta' NO se envían acá: el Manager ya los genera él mismo
        // al procesar la venta en /sync/ventas. Enviarlos también por acá descontaría el stock
        // dos veces en el Manager para la misma venta.
        if ($this->catalogoPendienteDeVerificar()) {
            return self::esperandoCatalogo();
        }

        $movimientos = MovimientoStock::pendientes()
            ->where('tipo', '!=', 'venta')
            ->get();

        if ($movimientos->isEmpty()) {
            return [
                'success' => true,
                'cantidad' => 0,
                'message' => 'No hay movimientos pendientes de sincronizar',
            ];
        }

        $movimientosData = $movimientos->map(fn ($mov) => [
            'uuid' => $mov->uuid,
            'product_id' => $mov->product_id,
            'tipo' => $mov->tipo,
            'cantidad' => $mov->cantidad,
            'referencia' => $mov->referencia,
            'fecha' => $mov->fecha->toIso8601String(),
        ])->toArray();

        $response = $this->managerApi->pushMovimientos($movimientosData);

        if (! $response['success']) {
            return $response;
        }

        $confirmados = collect($response['resultados'] ?? [])->pluck('uuid')->all();
        $sincronizados = 0;

        foreach ($movimientos as $movimiento) {
            if (! in_array($movimiento->uuid, $confirmados, true)) {
                continue;
            }

            $movimiento->marcarSincronizado();
            $sincronizados++;
        }

        return [
            'success' => true,
            'cantidad' => $sincronizados,
            'message' => $response['message'],
        ];
    }

    /**
     * Mientras el catálogo no se bajó entero no se sabe si los ids son los del Manager.
     * Mandar ventas en ese estado le descontaría stock a otros artículos, así que se
     * retienen: quedan pendientes y salen en el próximo push, ya con los ids corregidos.
     */
    private function catalogoPendienteDeVerificar(): bool
    {
        return Configuracion::get('ultima_sincronizacion_productos') === null;
    }

    /**
     * @return array{success: bool, cantidad: int, message: string}
     */
    private static function esperandoCatalogo(): array
    {
        return [
            'success' => true,
            'cantidad' => 0,
            'message' => 'Envío retenido hasta terminar de bajar el catálogo',
        ];
    }

    /**
     * Los movimientos de tipo 'venta' viajan dentro del push de la venta, no por /sync/movimientos.
     * Se marcan como sincronizados recién cuando el Manager confirma la venta que los originó.
     */
    /** La venta llegó al Manager por otro camino (facturar en el momento). */
    public function confirmarVentaEnviada(Venta $venta): void
    {
        $venta->marcarSincronizada();
        $this->marcarMovimientosDeVentaSincronizados($venta);
    }

    /** Mientras falta verificar el catálogo, no sale ninguna venta (ids sin alinear). */
    public function puedeEnviarVentas(): bool
    {
        return ! $this->catalogoPendienteDeVerificar();
    }

    private function marcarMovimientosDeVentaSincronizados(Venta $venta): void
    {
        if (! $venta->numero_venta) {
            return;
        }

        MovimientoStock::pendientes()
            ->where('tipo', 'venta')
            ->where('referencia', $venta->numero_venta)
            ->update([
                'sincronizado' => true,
                'sincronizado_at' => now(),
            ]);
    }

    /**
     * Sincronización bidireccional: pull + push.
     */
    public function syncBidireccional(): array
    {
        $resultados = [
            'pull' => ['success' => false],
            'push_ventas' => ['success' => false],
            'push_movimientos' => ['success' => false],
        ];

        // Pull: traer datos del manager
        $resultados['pull'] = $this->syncInicial();

        // Push: enviar ventas pendientes
        $resultados['push_ventas'] = $this->pushVentas();

        // Push: enviar movimientos pendientes
        $resultados['push_movimientos'] = $this->pushMovimientos();

        // Push: devoluciones (después de las ventas: referencian la venta por uuid)
        $resultados['push_devoluciones'] = $this->pushDevoluciones();

        // Push: turnos de caja (después de las ventas, así el Z llega con sus ventas)
        $resultados['push_turnos'] = $this->pushTurnos();

        $success = $resultados['pull']['success']
            && $resultados['push_ventas']['success']
            && $resultados['push_movimientos']['success']
            && $resultados['push_devoluciones']['success']
            && $resultados['push_turnos']['success'];

        return [
            'success' => $success,
            'resultados' => $resultados,
        ];
    }
}
