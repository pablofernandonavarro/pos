<?php

namespace App\Services;

use App\Models\Cajero;
use App\Models\Cliente;
use App\Models\CobroCuentaCorriente;
use App\Models\Configuracion;
use App\Models\DetalleVenta;
use App\Models\Devolucion;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\PagoVenta;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\PromocionBancaria;
use App\Models\TurnoCaja;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncService
{
    /** Ventas por request al Manager. */
    private const TANDA_VENTAS = 100;

    public function __construct(
        private readonly ManagerApiService $managerApi
    ) {
    }

    /**
     * Sincronización completa inicial (productos, precios, stock).
     */
    public function syncInicial(): array
    {
        $resultados = [
            'productos' => ['success' => false, 'cantidad' => 0],
            'precios' => ['success' => false, 'cantidad' => 0],
            'stock' => ['success' => false, 'cantidad' => 0],
        ];

        DB::beginTransaction();

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

            DB::commit();

            // Fuera de la transacción y sin abortar: sin promociones la caja vende igual,
            // y el pull de cada minuto las vuelve a intentar.
            $resultados['promociones'] = $this->syncPromociones();
            $resultados['cajeros'] = $this->syncCajeros();
            $resultados['facturacion'] = $this->syncFacturacion();
            $resultados['clientes'] = $this->syncClientes();

            return [
                'success' => true,
                'resultados' => $resultados,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
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
     */
    public function syncProductos(): array
    {
        $ultimaSync = Configuracion::get('ultima_sincronizacion_productos');
        $pedidoAt = now();

        $response = $this->managerApi->syncProductos($ultimaSync);

        if (! $response['success']) {
            return $response;
        }

        $productos = $response['data'];
        $sincronizados = 0;

        // Solo con el catálogo completo: con un delta no se ve el catálogo entero y el
        // emparejamiento podría mover un producto a un id que otro necesita.
        if ($ultimaSync === null) {
            $this->realinearIdsDeProductos($productos);
        }

        foreach ($productos as $productoData) {
            // El id tiene que ser el del Manager: es el que viaja en las ventas y el que
            // usa el stock. updateOrCreate() no sirve porque 'id' no es fillable, se
            // descartaba en silencio y SQLite asignaba su propio autoincremental.
            $producto = Producto::findOrNew($productoData['id']);

            if (! $producto->exists) {
                // El stock real lo trae syncStock. A uno existente no se le pisa: si el
                // pull de stock falla, la caja no queda con todo el catálogo en cero.
                $producto->stock = 0;
            }

            $producto->forceFill([
                'id' => $productoData['id'],
                'nombre' => $productoData['nombre'],
                'codigo_interno' => $productoData['codigo_interno'] ?? null,
                'codigo_barras' => $productoData['codigo_barras'] ?? null,
                'busqueda' => $productoData['busqueda'] ?? $productoData['nombre'],
                'precio' => $productoData['precio'] ?? 0,
                'costo' => $productoData['costo'] ?? 0,
                'stock_critico' => $productoData['stock_critico'] ?? 0,
                'imagen_url' => $productoData['imagen_url'] ?? null,
                'descripcion_web' => $productoData['descripcion_web'] ?? null,
                'marca' => $productoData['marca'] ?? null,
                'color' => $productoData['color'] ?? null,
                'n_talle' => $productoData['n_talle'] ?? null,
                'genero' => $productoData['genero'] ?? null,
                'n_grupo' => $productoData['n_grupo'] ?? null,
                'n_subgrupo' => $productoData['n_subgrupo'] ?? null,
                'n_temporada' => $productoData['n_temporada'] ?? null,
                'product_type' => $productoData['product_type'] ?? 'simple',
                'parent_id' => $productoData['parent_id'] ?? null,
                'modelo_codigo' => $productoData['parent_codigo_interno'] ?? null,
                'modelo_nombre' => $productoData['parent_nombre'] ?? null,
                'es_vendible' => $productoData['es_vendible'] ?? true,
                'activo' => true,
                'sincronizado_at' => now(),
            ])->save();

            $sincronizados++;
        }

        // La marca se compara con el updated_at del Manager, así que se usa su reloj
        // (synced_at) y no el de la caja. Con 2 minutos de solape: el Manager arma synced_at
        // después de consultar, y un producto guardado en ese medio quedaría afuera para
        // siempre. Volver a bajar un par de productos no cambia nada.
        $marca = ! empty($response['synced_at']) ? Carbon::parse($response['synced_at']) : $pedidoAt;
        Configuracion::set('ultima_sincronizacion_productos', $marca->subMinutes(2)->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
        ];
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
     * @param  array<int, array<string, mixed>>  $productosManager
     */
    private function realinearIdsDeProductos(array $productosManager): void
    {
        $firmaManager = [];
        $gruposManager = [];

        foreach (collect($productosManager)->sortBy('id') as $productoData) {
            $id = (int) $productoData['id'];
            $firmaManager[$id] = self::firmaDeProducto($productoData);
            $gruposManager[$firmaManager[$id]][] = $id;
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
        $codigoDe = collect($productosManager)->mapWithKeys(fn ($p) => [(int) $p['id'] => trim((string) ($p['codigo_interno'] ?? ''))]);
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
     */
    public function syncPrecios(): array
    {
        $response = $this->managerApi->syncPrecios();

        if (! $response['success']) {
            return $response;
        }

        $listas = $response['listas'];
        $precios = $response['precios'];

        // Sincronizar listas de precios
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

        // Limpiar precios anteriores
        Precio::truncate();

        // Sincronizar precios específicos
        $sincronizados = 0;
        foreach ($precios as $precioData) {
            Precio::create([
                'lista_precio_id' => $precioData['lista_precio_id'],
                'product_id' => $precioData['product_id'],
                'precio_override' => $precioData['precio_override'],
                'vigencia_desde' => $precioData['vigencia_desde'],
                'vigencia_hasta' => $precioData['vigencia_hasta'],
                'sincronizado_at' => now(),
            ]);

            $sincronizados++;
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

        $response = $this->managerApi->syncStock();

        if (! $response['success']) {
            return $response;
        }

        $stockData = $response['data'];
        $sincronizados = 0;

        foreach ($stockData as $stock) {
            $producto = Producto::find($stock['product_id']);

            if ($producto) {
                $producto->update([
                    'stock' => (int) $stock['cantidad'] + ($pendientes[$producto->id] ?? 0),
                ]);

                $sincronizados++;
            }
        }

        Configuracion::set('ultima_sincronizacion_stock', now()->toIso8601String());

        return [
            'success' => true,
            'cantidad' => $sincronizados,
        ];
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
