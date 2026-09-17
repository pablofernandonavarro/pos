<?php

namespace App\Livewire\Pos;

use App\Contracts\ImpresoraTickets;
use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\Cajero;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\ListaPrecio;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\PromocionBancaria;
use App\Services\AutorizacionService;
use App\Services\CajaService;
use App\Services\CajonService;
use App\Services\CuentaCorrienteService;
use App\Services\FacturacionService;
use App\Services\TicketService;
use App\Services\VentaService;
use App\Support\Dinero;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pantalla de venta. Arma el carrito y el cobro; registrar la venta, validar el cobro y
 * aplicar promociones lo hace VentaService (acá no se confía en nada que venga del
 * navegador).
 *
 * El estado que decide plata va #[Locked]: el navegador lo ve pero no lo puede modificar.
 * VentaService igual recalcula todo; el candado evita además que una autorización de
 * supervisor se pueda inventar desde el cliente.
 */
class Venta extends Component
{
    public string $busqueda = '';

    /** @var array<int, array{product_id: int, nombre: string, codigo: ?string, cantidad: int, precio_unitario: float, subtotal: float, stock_disponible: int}> */
    #[Locked]
    public array $carrito = [];

    #[Locked]
    public ?int $listaId = null;

    #[Locked]
    public float $subtotal = 0;

    #[Locked]
    public float $total = 0;

    public string $clienteNombre = '';

    public string $clienteDocumento = '';

    /** Condición frente al IVA del cliente (código de AFIP). 5 = consumidor final. */
    public string $clienteCondicionIva = '5';

    public string $clienteBusqueda = '';

    /** Cliente del padrón. Con él los datos de factura salen de su ficha y se puede vender a cuenta. */
    #[Locked]
    public ?int $clienteId = null;

    public array $resultadosBusqueda = [];

    // --- Selector de variante (color + talle) ---
    #[Locked]
    public ?string $selectorModelo = null;

    public string $selectorColor = '';

    public string $selectorTalle = '';

    // --- Apertura de caja ---
    public string $aperturaCajero = '';

    public string $aperturaCajeroId = '';

    public string $aperturaPin = '';

    public string $aperturaFondo = '';

    // --- Cobro ---
    #[Locked]
    public bool $cobrando = false;

    /** @var array<int, array<string, mixed>> Pagos ya agregados: lo que cargó el cajero + cómo quedó calculado. */
    #[Locked]
    public array $pagos = [];

    // --- Descuento manual ---
    public string $descuentoTipo = 'porcentaje';

    public string $descuentoValor = '';

    public string $descuentoSupervisorId = '';

    public string $descuentoPin = '';

    #[Locked]
    public int $descuentoManualCentavos = 0;

    #[Locked]
    public ?int $descuentoAutorizadoPorId = null;

    public string $pagoMedio = 'efectivo';

    public string $pagoMonto = '';

    public string $pagoRecibido = '';

    public string $pagoTarjeta = '';

    public string $pagoBanco = '';

    public string $pagoCuotas = '1';

    public ?int $pagoPromocionId = null;

    public string $pagoReferencia = '';

    // --- Vendedor activo: quién hace cada venta, distinto de quién abrió el turno ---
    public string $vendedorSelectId = '';

    public string $vendedorPin = '';

    // --- Mensajes ---
    public ?string $error = null;

    public ?string $exito = null;

    /** Aviso que no es error: la venta se registró pero la factura quedó pendiente. */
    public ?string $aviso = null;

    /** Última venta registrada, para reimprimir su ticket. */
    #[Locked]
    public ?int $ultimaVentaId = null;

    public function mount(): void
    {
        $this->listaId = ListaPrecio::getDefault()?->id;
    }

    // ------------------------------------------------------------------ Caja

    public function abrirCaja(CajaService $caja): void
    {
        $this->limpiarMensajes();

        $fondo = $this->aperturaFondo === '' ? 0 : $this->aperturaFondo;

        try {
            $turno = app(AutorizacionService::class)->hayCajeros()
                ? $caja->abrirConPin($this->aperturaCajeroId === '' ? null : (int) $this->aperturaCajeroId, $this->aperturaPin, $fondo)
                : $caja->abrir($this->aperturaCajero, $fondo);

            $this->exito = "Caja abierta: turno #{$turno->numero} · {$turno->cajero}";
            $this->reset(['aperturaCajero', 'aperturaCajeroId', 'aperturaFondo']);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();
        } finally {
            // El PIN nunca queda en el estado del componente (viaja al navegador).
            $this->aperturaPin = '';
        }
    }

    // ------------------------------------------------------------------ Búsqueda y carrito

    /**
     * Mientras se tipea solo se muestran resultados, nunca se agrega nada. Agregar en
     * cada tecla sumaba artículos a mitad de escritura: con la búsqueda por prefijo,
     * "ART-56" ya deja un único resultado antes de terminar de escribir el código.
     */
    public function updatedBusqueda(): void
    {
        $this->resultadosBusqueda = mb_strlen(trim($this->busqueda)) >= 2
            ? $this->consultarProductos($this->busqueda, 10)
            : [];
    }

    /**
     * Enter confirma: si la búsqueda deja un solo producto (código exacto o texto que
     * ya no admite dudas), se agrega. Es lo mismo que manda un lector de códigos de
     * barras al terminar de escanear, así que el escaneo sigue funcionando.
     *
     * El texto llega como parámetro desde el input y no se lee de $busqueda: el campo
     * tiene debounce, y un lector escanea y manda Enter antes de que se sincronice.
     */
    public function confirmarBusqueda(string $texto): void
    {
        $texto = trim($texto);

        if ($texto === '') {
            return;
        }

        $this->busqueda = $texto;

        // Código de un modelo (CONF-4301): se elige color y talle, nunca se agrega una
        // variante cualquiera. El código o el barcode de una variante concreta sigue de
        // largo y se agrega directo.
        if ($modelo = $this->modeloConCodigo($texto)) {
            $this->abrirSelectorVariantes($modelo);

            return;
        }

        $candidatos = $this->consultarProductos($texto, 2);

        if (count($candidatos) === 1) {
            // Se muestra antes de intentar agregarlo: si se rechaza (sin stock, no vendible)
            // la lista enseña el producto que se escaneó con su stock, en vez de quedar
            // con los resultados de la búsqueda anterior. Si se agrega, se limpia sola.
            $this->resultadosBusqueda = $candidatos;
            $this->agregarAlCarrito($candidatos[0]['id']);

            return;
        }

        $this->resultadosBusqueda = $this->consultarProductos($texto, 10);
    }

    /**
     * @return array<int, array{id: int, nombre: string, codigo: ?string, precio: float, stock: int, imagen: ?string}>
     */
    private function consultarProductos(string $texto, int $limite): array
    {
        return Producto::vendible()
            ->search($texto)
            ->limit($limite)
            ->get()
            ->map(fn ($producto) => [
                'id' => $producto->id,
                'nombre' => $producto->modelo_nombre ?? $producto->nombre,
                'codigo' => $producto->codigo_interno ?? $producto->codigo_barras,
                'precio' => $producto->getPrecioEfectivo($this->listaId),
                'stock' => $producto->stock,
                'imagen' => $producto->imagen_url,
                'variante' => $producto->descripcionVariante(),
                'modelo_codigo' => $producto->modelo_codigo,
            ])
            ->toArray();
    }

    // ------------------------------------------------------------------ Variantes

    /** Código de modelo si el texto es exactamente uno con variantes a la venta. */
    private function modeloConCodigo(string $texto): ?string
    {
        // Un código de variante o barcode exacto gana: es una prenda concreta.
        $concreto = Producto::vendible()
            ->where(fn ($q) => $q->whereRaw('codigo_interno = ? COLLATE NOCASE', [$texto])->orWhereRaw('codigo_barras = ? COLLATE NOCASE', [$texto]))
            ->exists();

        return $concreto ? null : Producto::vendible()->whereRaw('modelo_codigo = ? COLLATE NOCASE', [$texto])->value('modelo_codigo');
    }

    public function abrirSelectorVariantes(string $modeloCodigo): void
    {
        $this->limpiarMensajes();

        if ($this->cobrando || ! Producto::vendible()->where('modelo_codigo', $modeloCodigo)->exists()) {
            return;
        }

        $this->selectorModelo = $modeloCodigo;
        $this->selectorColor = '';
        $this->selectorTalle = '';
        $this->busqueda = '';
        $this->resultadosBusqueda = [];
    }

    public function cerrarSelectorVariantes(): void
    {
        $this->selectorModelo = null;
        $this->reset(['selectorColor', 'selectorTalle']);
    }

    /** Agrega la combinación elegida: esa variante (con su id, SKU y stock) va al carrito. */
    public function agregarVarianteSeleccionada(): void
    {
        $this->limpiarMensajes();

        if (! $this->selectorModelo) {
            return;
        }

        if ($this->selectorColor === '' || $this->selectorTalle === '') {
            $this->error = 'Elegí color y talle.';

            return;
        }

        $variante = Producto::vendible()
            ->where('modelo_codigo', $this->selectorModelo)
            ->where('color', $this->selectorColor)
            ->where('n_talle', $this->selectorTalle)
            ->first();

        if (! $variante) {
            $this->error = "No hay {$this->selectorColor} / {$this->selectorTalle} en este modelo.";

            return;
        }

        $this->agregarAlCarrito($variante->id);

        if ($this->error === null) {
            $this->cerrarSelectorVariantes();
        }
    }

    public function agregarAlCarrito(int $productoId): void
    {
        $this->limpiarMensajes();

        if ($this->cobrando) {
            return;
        }

        $producto = Producto::find($productoId);

        if (! $producto || ! $producto->es_vendible || ! $producto->activo) {
            $this->error = 'Producto no disponible';

            return;
        }

        if ($producto->stock <= 0) {
            $this->error = "Sin stock de {$producto->nombre}";

            return;
        }

        $key = array_search($productoId, array_column($this->carrito, 'product_id'));

        if ($key !== false) {
            if ($this->carrito[$key]['cantidad'] >= $producto->stock) {
                $this->error = "Stock insuficiente de {$producto->nombre}: hay {$producto->stock}";

                return;
            }

            $this->carrito[$key]['cantidad']++;
        } else {
            $this->carrito[] = [
                'product_id' => $producto->id,
                'nombre' => $producto->modelo_nombre ?? $producto->nombre,
                'imagen' => $producto->imagen_url,
                'codigo' => $producto->codigo_interno ?? $producto->codigo_barras,
                // Para una variante: modelo, "Negro / M" y el barcode. Simples: null.
                'modelo_codigo' => $producto->modelo_codigo,
                'variante' => $producto->descripcionVariante(),
                'codigo_barras' => $producto->codigo_barras,
                'cantidad' => 1,
                'precio_unitario' => $producto->getPrecioEfectivo($this->listaId),
                'subtotal' => 0,
                'stock_disponible' => $producto->stock,
            ];
        }

        $this->calcularTotales();
        $this->busqueda = '';
        $this->resultadosBusqueda = [];
    }

    public function incrementarCantidad(int $index): void
    {
        if ($this->cobrando || ! isset($this->carrito[$index])) {
            return;
        }

        if ($this->carrito[$index]['cantidad'] < $this->carrito[$index]['stock_disponible']) {
            $this->carrito[$index]['cantidad']++;
            $this->calcularTotales();
        } else {
            $this->error = 'Stock insuficiente';
        }
    }

    public function decrementarCantidad(int $index): void
    {
        if ($this->cobrando || ! isset($this->carrito[$index])) {
            return;
        }

        if ($this->carrito[$index]['cantidad'] > 1) {
            $this->carrito[$index]['cantidad']--;
            $this->calcularTotales();
        }
    }

    public function eliminarItem(int $index): void
    {
        if ($this->cobrando) {
            return;
        }

        unset($this->carrito[$index]);
        $this->carrito = array_values($this->carrito);
        $this->calcularTotales();
    }

    public function calcularTotales(): void
    {
        foreach ($this->carrito as $i => $item) {
            $this->carrito[$i]['subtotal'] = Dinero::pesos(Dinero::centavos($item['precio_unitario'] * $item['cantidad']));
        }

        // El total sale del mismo cálculo que usa VentaService al registrar.
        try {
            $centavos = app(VentaService::class)->subtotalCentavos($this->itemsParaServicio(), $this->listaId);
        } catch (CajaException $e) {
            $centavos = array_sum(array_map(fn ($i) => Dinero::centavos($i['subtotal']), $this->carrito));
            $this->error = $e->getMessage();
        }

        $this->subtotal = Dinero::pesos($centavos);
        $this->total = $this->subtotal;
    }

    // ------------------------------------------------------------------ Cobro

    public function abrirCobro(): void
    {
        $this->limpiarMensajes();

        if ($this->carrito === []) {
            $this->error = 'El carrito está vacío';

            return;
        }

        $this->calcularTotales();
        $this->pagos = [];
        $this->cobrando = true;
        $this->resetFormularioPago();
    }

    public function cancelarCobro(): void
    {
        $this->cobrando = false;
        $this->pagos = [];
        $this->quitarDescuento();
        $this->limpiarMensajes();
    }

    /**
     * Fija quién vende a partir de ahora, sin tocar el turno de caja: varias vendedoras
     * pueden usar la misma caja abierta a lo largo del día. Se guarda en Configuracion (no
     * en una propiedad del componente) para que sobreviva un F5 y un reinicio de la app.
     */
    public function fijarVendedor(AutorizacionService $autorizacion): void
    {
        $this->error = null;

        try {
            $cajero = $autorizacion->verificar($this->vendedorSelectId === '' ? null : (int) $this->vendedorSelectId, $this->vendedorPin);

            Configuracion::set('vendedor_activo_id', (string) $cajero->id);
            Configuracion::set('vendedor_activo_nombre', $cajero->nombre);
            $this->vendedorSelectId = '';
        } catch (CajaException $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->vendedorPin = '';
        }
    }

    /**
     * Descuento manual sobre el total. Hasta el límite configurado lo aplica el cajero; por
     * encima hace falta que un supervisor ponga su PIN acá mismo.
     */
    public function aplicarDescuento(VentaService $ventas, AutorizacionService $autorizacion): void
    {
        $this->error = null;

        if ($this->pagos !== []) {
            $this->error = 'Quitá los pagos cargados antes de cambiar el descuento.';

            return;
        }

        $subtotal = Dinero::centavos($this->total);
        $valor = (float) str_replace(',', '.', $this->descuentoValor);
        $centavos = $this->descuentoTipo === 'porcentaje'
            ? (int) round($subtotal * $valor / 100)
            : Dinero::centavos($valor);

        try {
            $autoriza = null;
            $porcentaje = $subtotal > 0 ? $centavos * 100 / $subtotal : 0;

            if ($porcentaje > VentaService::limiteDescuentoSinAutorizacion() + 0.0001) {
                $autoriza = $autorizacion->verificar(
                    $this->descuentoSupervisorId === '' ? null : (int) $this->descuentoSupervisorId,
                    $this->descuentoPin,
                    requiereSupervisor: true
                );
            }

            $this->descuentoManualCentavos = $ventas->validarDescuentoManual($subtotal, Dinero::pesos($centavos), $autoriza);
            $this->descuentoAutorizadoPorId = $autoriza?->id;
            $this->descuentoValor = '';
        } catch (CajaException $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->descuentoPin = '';
        }

        $this->resetFormularioPago();
    }

    public function quitarDescuento(): void
    {
        $this->descuentoManualCentavos = 0;
        $this->descuentoAutorizadoPorId = null;
        $this->reset(['descuentoValor', 'descuentoSupervisorId', 'descuentoPin']);

        if ($this->cobrando && $this->pagos === []) {
            $this->resetFormularioPago();
        }
    }

    public function elegirCliente(int $id): void
    {
        $cliente = Cliente::find($id);

        if (! $cliente) {
            return;
        }

        $this->clienteId = $cliente->id;
        $this->clienteNombre = $cliente->nombre;
        $this->clienteDocumento = (string) $cliente->documentoFormateado();
        $this->clienteCondicionIva = (string) $cliente->condicion_iva;
        $this->clienteBusqueda = '';
    }

    public function quitarCliente(): void
    {
        if ($this->pagos !== [] && collect($this->pagos)->contains(fn ($p) => ($p['calculo']['medio'] ?? null) === 'cuenta_corriente')) {
            $this->error = 'Quitá el pago a cuenta corriente antes de cambiar el cliente.';

            return;
        }

        $this->reset(['clienteId', 'clienteNombre', 'clienteDocumento', 'clienteCondicionIva', 'clienteBusqueda']);

        if ($this->pagoMedio === 'cuenta_corriente') {
            $this->pagoMedio = 'efectivo';
        }
    }

    public function elegirMedio(string $medio): void
    {
        if (! array_key_exists($medio, PagoVenta::MEDIOS)) {
            return;
        }

        if ($medio === 'cuenta_corriente' && ! ($this->clienteId && Cliente::whereKey($this->clienteId)->where('cuenta_corriente', true)->exists())) {
            $this->error = 'Para vender a cuenta elegí un cliente con cuenta corriente.';

            return;
        }

        $this->pagoMedio = $medio;
        $this->pagoPromocionId = null;
        $this->error = null;
    }

    /** Si cambian los datos del pago, la promoción elegida puede dejar de aplicar. */
    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['pagoMonto', 'pagoTarjeta', 'pagoBanco', 'pagoMedio'], true)) {
            $this->pagoPromocionId = null;
        }
    }

    public function agregarPago(VentaService $ventas): void
    {
        $this->error = null;
        $entrada = $this->entradaPago();

        try {
            $calculo = $ventas->calcularPago($entrada);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($calculo['monto'] > $this->faltaCentavos()) {
            $this->error = 'El pago supera lo que falta cobrar ('.Dinero::formato(Dinero::pesos($this->faltaCentavos())).').';

            return;
        }

        $this->pagos[] = ['entrada' => $entrada, 'calculo' => $calculo];
        $this->resetFormularioPago();
    }

    public function quitarPago(int $indice): void
    {
        unset($this->pagos[$indice]);
        $this->pagos = array_values($this->pagos);
        $this->resetFormularioPago();
    }

    public function finalizarVenta(VentaService $ventas): void
    {
        $this->error = null;
        $this->calcularTotales();

        // Atajo: si falta cobrar y el formulario tiene un pago cargado, se agrega primero.
        if ($this->faltaCentavos() > 0 && $this->pagoMonto !== '') {
            $this->agregarPago($ventas);

            if ($this->error) {
                return;
            }
        }

        if ($this->faltaCentavos() !== 0) {
            $this->error = 'Falta cobrar '.Dinero::formato(Dinero::pesos($this->faltaCentavos())).'.';

            return;
        }

        try {
            $venta = $ventas->registrar(
                $this->itemsParaServicio(),
                array_map(fn ($p) => (array) ($p['entrada'] ?? []), $this->pagos),
                $this->listaId,
                ['nombre' => $this->clienteNombre, 'documento' => $this->clienteDocumento, 'condicion_iva' => $this->clienteCondicionIva, 'cliente_id' => $this->clienteId],
                Dinero::pesos($this->descuentoManualCentavos),
                $this->descuentoAutorizadoPorId ? Cajero::find($this->descuentoAutorizadoPorId) : null
            );
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // La factura se pide antes de imprimir, para que el ticket salga con CAE. Si no se
        // puede (sin conexión, AFIP caído) queda pendiente y se avisa: la venta no se toca.
        $this->aviso = app(FacturacionService::class)->facturarAhora($venta);

        // Fuera de la transacción: si hizo rollback no hay nada que sincronizar.
        SincronizarPendientes::dispatch();

        $vuelto = array_sum(array_map(fn ($p) => (int) ($p['calculo']['vuelto'] ?? 0), $this->pagos));

        $this->dispatch('venta-finalizada', ventaId: $venta->id, numeroVenta: $venta->numero_venta);
        $this->resetearVenta();
        $this->ultimaVentaId = $venta->id;
        $this->exito = "Venta {$venta->numero_venta} registrada · ".Dinero::formato($venta->total)
            .($vuelto > 0 ? ' · Vuelto '.Dinero::formato(Dinero::pesos($vuelto)) : '');

        // Cajón: solo si entró efectivo. Si no abre se avisa, la venta ya está hecha.
        if ($motivoCajon = app(CajonService::class)->abrirPorEfectivo($venta->pagos->contains('medio', 'efectivo'))) {
            $this->aviso = trim(($this->aviso ? $this->aviso.' ' : '').$motivoCajon);
        }

        // La venta ya está registrada: si la impresora falla se avisa, pero no se deshace nada.
        $tickets = app(TicketService::class);

        if ($tickets->imprimeAutomatico() && ($motivo = $tickets->imprimirVenta($venta)) !== null) {
            $this->error = "No se imprimió el ticket: {$motivo}";
        }
    }

    public function imprimirUltimoTicket(TicketService $tickets): void
    {
        $venta = $this->ultimaVentaId ? \App\Models\Venta::find($this->ultimaVentaId) : null;

        if (! $venta) {
            return;
        }

        $motivo = $tickets->imprimirVenta($venta);
        $this->error = $motivo === null ? null : "No se imprimió el ticket: {$motivo}";
    }

    public function resetearVenta(): void
    {
        $this->reset(['carrito', 'subtotal', 'total', 'clienteNombre', 'clienteDocumento', 'clienteCondicionIva', 'clienteBusqueda', 'clienteId', 'busqueda', 'resultadosBusqueda', 'cobrando', 'pagos']);
        $this->quitarDescuento();
        $this->cerrarSelectorVariantes();
        $this->resetFormularioPago();
    }

    public function limpiarMensajes(): void
    {
        $this->error = null;
        $this->exito = null;
        $this->aviso = null;
    }

    // ------------------------------------------------------------------ Internos

    /** @return array<int, array{product_id: int, cantidad: int}> */
    private function itemsParaServicio(): array
    {
        return array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'cantidad' => (int) $i['cantidad']], $this->carrito);
    }

    /** @return array<string, mixed> */
    private function entradaPago(): array
    {
        return [
            'medio' => $this->pagoMedio,
            'monto' => $this->pagoMonto === '' ? 0 : $this->pagoMonto,
            'recibido' => $this->pagoMedio === 'efectivo' && $this->pagoRecibido !== '' ? $this->pagoRecibido : null,
            'tarjeta' => $this->pagoTarjeta ?: null,
            'banco' => $this->pagoBanco ?: null,
            'cuotas' => (int) $this->pagoCuotas ?: 1,
            'promocion_id' => $this->pagoPromocionId,
            'referencia' => $this->pagoReferencia ?: null,
        ];
    }

    private function faltaCentavos(): int
    {
        return Dinero::centavos($this->total) - $this->descuentoManualCentavos
            - array_sum(array_map(fn ($p) => (int) ($p['calculo']['monto'] ?? 0), $this->pagos));
    }

    private function resetFormularioPago(): void
    {
        $this->pagoMedio = 'efectivo';
        $this->pagoMonto = $this->cobrando && $this->faltaCentavos() > 0 ? (string) Dinero::pesos($this->faltaCentavos()) : '';
        $this->pagoRecibido = '';
        $this->pagoTarjeta = '';
        $this->pagoBanco = '';
        $this->pagoCuotas = '1';
        $this->pagoPromocionId = null;
        $this->pagoReferencia = '';
    }

    /**
     * Colores, talles y la grilla con el stock de cada combinación del modelo.
     *
     * @return array{codigo: string, nombre: string, precio: float, colores: list<string>, talles: list<string>, grilla: array<string, array<string, array{id: int, stock: int, sku: ?string}>>}|null
     */
    private function datosSelector(string $modeloCodigo): ?array
    {
        $variantes = Producto::vendible()->where('modelo_codigo', $modeloCodigo)->orderBy('id')->get();

        if ($variantes->isEmpty()) {
            return null;
        }

        $grilla = [];

        foreach ($variantes as $v) {
            $grilla[(string) $v->color][(string) $v->n_talle] = ['id' => $v->id, 'stock' => $v->stock, 'sku' => $v->codigo_interno];
        }

        return [
            'codigo' => $modeloCodigo,
            'nombre' => $variantes->first()->modelo_nombre ?? $modeloCodigo,
            'precio' => $variantes->first()->getPrecioEfectivo($this->listaId),
            'colores' => $variantes->pluck('color')->map(fn ($c) => (string) $c)->unique()->values()->all(),
            'talles' => $variantes->pluck('n_talle')->map(fn ($t) => (string) $t)->unique()
                ->sortBy(fn ($t) => [Producto::ordenTalle($t), $t])->values()->all(),
            'grilla' => $grilla,
        ];
    }

    public function render()
    {
        $turno = app(CajaService::class)->turnoAbierto();

        $promociones = collect();
        $calculoPago = null;

        if ($this->cobrando) {
            $monto = Dinero::centavos($this->pagoMonto === '' ? 0 : $this->pagoMonto);

            $promociones = PromocionBancaria::orderBy('nombre')->get()
                ->filter(fn (PromocionBancaria $p) => $p->aplicaA($this->pagoMedio, $this->pagoTarjeta ?: null, $this->pagoBanco ?: null, $monto))
                ->map(fn (PromocionBancaria $p) => ['promo' => $p, 'descuento' => $p->descuentoPara($monto)])
                ->values();

            // Vista previa del pago tal como quedaría registrado.
            try {
                $calculoPago = $monto > 0 ? app(VentaService::class)->calcularPago($this->entradaPago()) : null;
            } catch (CajaException) {
                $calculoPago = null;
            }
        }

        $cuentas = app(CuentaCorrienteService::class);
        $cliente = $this->clienteId ? Cliente::find($this->clienteId) : null;
        $selector = $this->selectorModelo ? $this->datosSelector($this->selectorModelo) : null;
        $disponible = $cliente?->cuenta_corriente ? $cuentas->disponibleCentavos($cliente) : null;

        return view('livewire.pos.venta', [
            'turno' => $turno,
            'cajeros' => $turno ? collect() : Cajero::orderBy('nombre')->get(['id', 'nombre', 'rol']),
            'cajerosParaVendedor' => Cajero::orderBy('nombre')->get(['id', 'nombre']),
            'vendedorActivoNombre' => Configuracion::get('vendedor_activo_nombre'),
            'supervisores' => $this->cobrando ? Cajero::where('rol', 'supervisor')->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'limiteDescuento' => VentaService::limiteDescuentoSinAutorizacion(),
            'descuentoAutorizadoPor' => $this->descuentoAutorizadoPorId ? Cajero::find($this->descuentoAutorizadoPorId)?->nombre : null,
            'imprimeDirecto' => app(ImpresoraTickets::class)->puedeImprimirDirecto(),
            'falta' => Dinero::pesos(max(0, $this->faltaCentavos())),
            'promocionesAplicables' => $promociones,
            'calculoPago' => $calculoPago,
            'bancosConocidos' => PromocionBancaria::whereNotNull('banco')->distinct()->orderBy('banco')->pluck('banco'),
            'descuentoTotal' => Dinero::pesos(array_sum(array_map(fn ($p) => (int) ($p['calculo']['descuento'] ?? 0), $this->pagos))),
            'letraFactura' => FacturacionService::letraPara((int) $this->clienteCondicionIva),
            'selector' => $selector,
            'clienteElegido' => $cliente,
            'saldoCliente' => $cliente ? Dinero::pesos($cuentas->saldoCentavos($cliente)) : null,
            'disponibleCliente' => $disponible === null ? null : Dinero::pesos($disponible),
            'resultadosClientes' => $cliente ? collect() : $cuentas->buscar($this->clienteBusqueda, 6),
        ])->layout('layouts.pos');
    }
}
