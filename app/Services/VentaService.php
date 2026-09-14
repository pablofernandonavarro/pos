<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Models\DetalleVenta;
use App\Models\MovimientoStock;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\PromocionBancaria;
use App\Models\Venta;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Registra una venta con sus pagos.
 *
 * Todo se recalcula acá y no se confía en lo que mande la pantalla: el carrito y los
 * pagos viven en propiedades públicas de Livewire, que el navegador puede alterar. Precio,
 * stock, descuento de la promoción y que el cobro cierre exacto se verifican de nuevo.
 */
class VentaService
{
    public function __construct(
        private readonly CajaService $caja
    ) {
    }

    /**
     * Calcula cómo queda un pago con su promoción, sin registrar nada. La pantalla de cobro
     * usa esto mismo, así lo que ve el cajero es lo que después se registra.
     *
     * @param  array{medio: string, monto: int|float|string, tarjeta?: ?string, banco?: ?string, cuotas?: ?int, promocion_id?: ?int, recibido?: int|float|string|null, referencia?: ?string}  $pago
     * @return array{medio: string, monto: int, descuento: int, importe: int, recibido: ?int, vuelto: ?int, tarjeta: ?string, banco: ?string, cuotas: ?int, promocion_id: ?int, promocion_nombre: ?string, referencia: ?string}
     */
    public function calcularPago(array $pago): array
    {
        $medio = (string) ($pago['medio'] ?? '');

        if (! array_key_exists($medio, PagoVenta::MEDIOS)) {
            throw new CajaException('Medio de pago inválido.');
        }

        $monto = Dinero::centavos($pago['monto'] ?? 0);

        if ($monto <= 0) {
            throw new CajaException('Cada pago tiene que ser mayor a cero.');
        }

        $conTarjeta = in_array($medio, PagoVenta::CON_TARJETA, true);
        $tarjeta = $conTarjeta ? (($pago['tarjeta'] ?? null) ?: null) : null;
        $banco = in_array($medio, [...PagoVenta::CON_TARJETA, 'qr'], true) ? (trim((string) ($pago['banco'] ?? '')) ?: null) : null;
        $cuotas = $medio === 'credito' ? max(1, (int) ($pago['cuotas'] ?? 1)) : null;

        if ($tarjeta !== null && ! array_key_exists($tarjeta, PagoVenta::TARJETAS)) {
            throw new CajaException('Tarjeta inválida.');
        }

        $descuento = 0;
        $promocion = null;

        if (! empty($pago['promocion_id'])) {
            $promocion = PromocionBancaria::find((int) $pago['promocion_id']);

            if (! $promocion || ! $promocion->aplicaA($medio, $tarjeta, $banco, $monto)) {
                throw new CajaException('La promoción elegida no aplica a este pago. Revisá medio, tarjeta, banco o monto.');
            }

            if ($promocion->cuotas_sin_interes && $cuotas !== null && $cuotas > $promocion->cuotas_sin_interes) {
                throw new CajaException("La promoción da hasta {$promocion->cuotas_sin_interes} cuotas sin interés.");
            }

            $descuento = $promocion->descuentoPara($monto);
        }

        $importe = $monto - $descuento;
        $recibido = $vuelto = null;

        if ($medio === 'efectivo' && ($pago['recibido'] ?? null) !== null && $pago['recibido'] !== '') {
            $recibido = Dinero::centavos($pago['recibido']);

            if ($recibido < $importe) {
                throw new CajaException('El efectivo recibido no alcanza para este pago.');
            }

            $vuelto = $recibido - $importe;
        }

        return [
            'medio' => $medio,
            'monto' => $monto,
            'descuento' => $descuento,
            'importe' => $importe,
            'recibido' => $recibido,
            'vuelto' => $vuelto,
            'tarjeta' => $tarjeta,
            'banco' => $banco,
            'cuotas' => $cuotas,
            'promocion_id' => $promocion?->id,
            'promocion_nombre' => $promocion?->nombre,
            'referencia' => ($r = trim((string) ($pago['referencia'] ?? ''))) === '' ? null : mb_substr($r, 0, 60),
        ];
    }

    /** Porcentaje de descuento manual que el cajero puede hacer sin supervisor. */
    public static function limiteDescuentoSinAutorizacion(): float
    {
        return max(0, min(100, (float) Configuracion::get('descuento_maximo_sin_autorizacion', 0)));
    }

    /**
     * Valida un descuento manual sobre el subtotal. Devuelve los centavos a descontar.
     */
    public function validarDescuentoManual(int $subtotalCentavos, int|float|string $descuento, ?Cajero $autoriza): int
    {
        $centavos = Dinero::centavos($descuento);

        if ($centavos === 0) {
            return 0;
        }

        if ($centavos < 0) {
            throw new CajaException('El descuento no puede ser negativo.');
        }

        if ($centavos >= $subtotalCentavos) {
            throw new CajaException('El descuento no puede ser igual o mayor al total.');
        }

        $porcentaje = $centavos * 100 / $subtotalCentavos;
        $limite = self::limiteDescuentoSinAutorizacion();

        if ($porcentaje > $limite + 0.0001 && ! $autoriza?->esSupervisor()) {
            throw new CajaException(sprintf('Un descuento del %s%% necesita autorización de un supervisor (límite %s%%).',
                rtrim(rtrim(number_format($porcentaje, 2, ',', ''), '0'), ','), rtrim(rtrim(number_format($limite, 2, ',', ''), '0'), ',')));
        }

        return $centavos;
    }

    /**
     * @param  array<int, array{product_id: int, cantidad: int}>  $items
     * @param  array<int, array<string, mixed>>  $pagos
     * @param  array{nombre?: ?string, documento?: ?string}  $cliente
     * @param  Cajero|null  $autorizaDescuento  Supervisor ya verificado con PIN.
     */
    public function registrar(array $items, array $pagos, ?int $listaId = null, array $cliente = [], int|float|string $descuentoManual = 0, ?Cajero $autorizaDescuento = null): Venta
    {
        $turno = $this->caja->turnoAbierto();

        if (! $turno) {
            throw new CajaException('La caja está cerrada. Abrila para poder vender.');
        }

        if ($items === []) {
            throw new CajaException('El carrito está vacío.');
        }

        if ($pagos === []) {
            throw new CajaException('Falta registrar el cobro.');
        }

        return DB::transaction(function () use ($turno, $items, $pagos, $listaId, $cliente, $descuentoManual, $autorizaDescuento) {
            $lineas = $this->armarLineas($items, $listaId);
            $subtotal = array_sum(array_column($lineas, 'subtotal'));
            $manual = $this->validarDescuentoManual($subtotal, $descuentoManual, $autorizaDescuento);
            $aCobrar = $subtotal - $manual;

            $pagosCalculados = array_map(fn (array $p) => $this->calcularPago($p), $pagos);
            $cubierto = array_sum(array_column($pagosCalculados, 'monto'));

            if ($cubierto !== $aCobrar) {
                $diferencia = Dinero::formato(Dinero::pesos(abs($aCobrar - $cubierto)));

                throw new CajaException($cubierto < $aCobrar
                    ? "El cobro no cubre el total: faltan {$diferencia}."
                    : "El cobro supera el total por {$diferencia}.");
            }

            $descuento = $manual + array_sum(array_column($pagosCalculados, 'descuento'));
            $medios = array_unique(array_column($pagosCalculados, 'medio'));

            $venta = Venta::create([
                'lista_precio_id' => $listaId,
                'turno_caja_id' => $turno->id,
                'cajero' => $turno->cajero,
                'numero_venta' => Venta::generarNumeroVenta(),
                'fecha' => now(),
                'subtotal' => Dinero::pesos($subtotal),
                'descuento' => Dinero::pesos($descuento),
                'descuento_manual' => Dinero::pesos($manual),
                'descuento_autorizado_por' => $manual > 0 ? $autorizaDescuento?->nombre : null,
                'total' => Dinero::pesos($subtotal - $descuento),
                'metodo_pago' => count($medios) === 1 ? reset($medios) : 'mixto',
                'cliente_nombre' => ($n = trim((string) ($cliente['nombre'] ?? ''))) === '' ? null : mb_substr($n, 0, 150),
                'cliente_documento' => ($d = trim((string) ($cliente['documento'] ?? ''))) === '' ? null : mb_substr($d, 0, 30),
            ]);

            foreach ($lineas as $linea) {
                DetalleVenta::create([
                    'venta_id' => $venta->id,
                    'product_id' => $linea['producto']->id,
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'subtotal' => Dinero::pesos($linea['subtotal']),
                ]);

                MovimientoStock::registrar($linea['producto']->id, 'venta', -$linea['cantidad'], $venta->numero_venta);
            }

            foreach ($pagosCalculados as $pago) {
                $venta->pagos()->create([
                    ...$pago,
                    'monto' => Dinero::pesos($pago['monto']),
                    'descuento' => Dinero::pesos($pago['descuento']),
                    'importe' => Dinero::pesos($pago['importe']),
                    'recibido' => $pago['recibido'] === null ? null : Dinero::pesos($pago['recibido']),
                    'vuelto' => $pago['vuelto'] === null ? null : Dinero::pesos($pago['vuelto']),
                ]);
            }

            return $venta->load(['pagos', 'detalles']);
        });
    }

    /**
     * Total del carrito en centavos, calculado igual que al registrar.
     *
     * @param  array<int, array{product_id: int, cantidad: int}>  $items
     */
    public function subtotalCentavos(array $items, ?int $listaId = null): int
    {
        return $items === [] ? 0 : array_sum(array_column($this->armarLineas($items, $listaId), 'subtotal'));
    }

    /**
     * @param  array<int, array{product_id: int, cantidad: int}>  $items
     * @return array<int, array{producto: Producto, cantidad: int, precio_unitario: float, subtotal: int}>
     */
    private function armarLineas(array $items, ?int $listaId): array
    {
        // Mismo producto en dos líneas cuenta junto para el control de stock.
        $cantidades = [];

        foreach ($items as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            $cantidad = (int) ($item['cantidad'] ?? 0);

            if ($cantidad <= 0) {
                throw new CajaException('Hay una línea con cantidad inválida.');
            }

            $cantidades[$id] = ($cantidades[$id] ?? 0) + $cantidad;
        }

        $productos = Producto::whereIn('id', array_keys($cantidades))->lockForUpdate()->get()->keyBy('id');
        $lineas = [];

        foreach ($cantidades as $id => $cantidad) {
            $producto = $productos->get($id);

            if (! $producto || ! $producto->es_vendible || ! $producto->activo) {
                throw new CajaException('Un producto del carrito ya no está disponible para la venta.');
            }

            if ($cantidad > $producto->stock) {
                throw new CajaException("Stock insuficiente de {$producto->nombre}: hay {$producto->stock}.");
            }

            $precio = $producto->getPrecioEfectivo($listaId);

            $lineas[] = [
                'producto' => $producto,
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'subtotal' => Dinero::centavos($precio * $cantidad),
            ];
        }

        return $lineas;
    }
}
