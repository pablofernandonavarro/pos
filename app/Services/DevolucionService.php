<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Models\DetalleVenta;
use App\Models\Devolucion;
use App\Models\MovimientoStock;
use App\Models\Venta;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Devoluciones y anulaciones de ventas.
 *
 * Siempre con autorización de un supervisor y dentro de un turno abierto: la plata que se
 * reintegra en efectivo sale del cajón de ese turno y descuenta de su arqueo.
 */
class DevolucionService
{
    public function __construct(
        private readonly CajaService $caja,
    ) {
    }

    /**
     * Importe a devolver por cada unidad de una línea, en centavos: el precio de la línea
     * con los descuentos de la venta prorrateados. Si la venta tuvo 10% de descuento, se
     * devuelve lo que el cliente pagó, no el precio de lista.
     */
    public function importeUnitarioCentavos(DetalleVenta $detalle, Venta $venta): float
    {
        $subtotalVenta = Dinero::centavos($venta->subtotal);

        if ($subtotalVenta === 0 || $detalle->cantidad === 0) {
            return 0;
        }

        $proporcionCobrada = Dinero::centavos($venta->total) / $subtotalVenta;

        return Dinero::centavos($detalle->subtotal) * $proporcionCobrada / $detalle->cantidad;
    }

    /**
     * @param  array<int|string, int|string>  $cantidades  detalle_venta_id => unidades a devolver
     */
    public function registrar(Venta $venta, array $cantidades, string $motivo, string $reintegro, Cajero $autoriza): Devolucion
    {
        $motivo = trim($motivo);

        if (! $autoriza->esSupervisor()) {
            throw new CajaException('Las devoluciones las autoriza un supervisor.');
        }

        if ($motivo === '') {
            throw new CajaException('Indicá el motivo de la devolución.');
        }

        if (! array_key_exists($reintegro, Devolucion::REINTEGROS)) {
            throw new CajaException('Elegí cómo se reintegra la plata.');
        }

        $turno = $this->caja->turnoAbierto();

        if (! $turno) {
            throw new CajaException('Abrí la caja para registrar una devolución.');
        }

        $cantidades = collect($cantidades)
            ->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c])
            ->filter(fn (int $c) => $c > 0);

        if ($cantidades->isEmpty()) {
            throw new CajaException('Indicá qué unidades se devuelven.');
        }

        $devolucion = DB::transaction(function () use ($venta, $cantidades, $motivo, $reintegro, $autoriza, $turno) {
            $venta = Venta::with('detalles.devoluciones')->lockForUpdate()->findOrFail($venta->id);
            $detalles = $venta->detalles->keyBy('id');

            $lineas = [];
            $total = 0;
            $todoDevuelto = true;

            foreach ($detalles as $detalle) {
                $yaDevuelto = (int) $detalle->devoluciones->sum('cantidad');
                $pedido = $cantidades->get($detalle->id, 0);

                if ($pedido > $detalle->cantidad - $yaDevuelto) {
                    throw new CajaException('No se pueden devolver más unidades de las vendidas ('.($detalle->cantidad - $yaDevuelto).' disponibles en una línea).');
                }

                if ($yaDevuelto + $pedido < $detalle->cantidad) {
                    $todoDevuelto = false;
                }

                if ($pedido > 0) {
                    $importe = (int) round($this->importeUnitarioCentavos($detalle, $venta) * $pedido);
                    $lineas[] = ['detalle' => $detalle, 'cantidad' => $pedido, 'importe' => $importe];
                    $total += $importe;
                }
            }

            if (count($lineas) !== $cantidades->count()) {
                throw new CajaException('Una de las líneas no pertenece a esta venta.');
            }

            // El redondeo por línea no puede hacer devolver más de lo cobrado: si se devuelve
            // todo lo que queda, el total cierra exacto contra la venta; si no, nunca lo supera.
            $restante = Dinero::centavos($venta->total) - Dinero::centavos($venta->devoluciones()->sum('total'));
            $ajuste = ($todoDevuelto ? $restante : min($total, $restante)) - $total;

            if ($ajuste !== 0) {
                $lineas[array_key_last($lineas)]['importe'] += $ajuste;
                $total += $ajuste;
            }

            // Una venta que se llevó entera a cuenta no devuelve efectivo que nunca entró: se
            // acredita en la cuenta del cliente.
            $venta->loadMissing('pagos');

            if ($reintegro === 'efectivo' && $venta->pagos->isNotEmpty() && $venta->pagos->every(fn ($p) => $p->medio === 'cuenta_corriente')) {
                throw new CajaException('La venta fue a cuenta corriente: la devolución se acredita en la cuenta (elegí "mismo medio de pago").');
            }

            if ($reintegro === 'efectivo') {
                $disponible = Dinero::centavos($this->caja->resumen($turno)['efectivo']['esperado']);

                if ($total > $disponible) {
                    throw new CajaException('No hay efectivo suficiente en la caja para reintegrar '.Dinero::formato(Dinero::pesos($total)).'.');
                }
            }

            $devolucion = Devolucion::create([
                'venta_id' => $venta->id,
                'turno_caja_id' => $turno->id,
                'numero' => sprintf('DEV%02d-%06d', (int) Configuracion::get('punto_de_venta_id', 0), (int) Devolucion::max('id') + 1),
                // Anulación = se devuelve todo y es la primera devolución de la venta.
                'tipo' => $todoDevuelto && $venta->detalles->every(fn ($d) => $d->devoluciones->isEmpty()) ? 'anulacion' : 'parcial',
                'motivo' => mb_substr($motivo, 0, 200),
                'reintegro' => $reintegro,
                'total' => Dinero::pesos($total),
                'autorizado_por' => $autoriza->nombre,
            ]);

            foreach ($lineas as $linea) {
                $devolucion->items()->create([
                    'detalle_venta_id' => $linea['detalle']->id,
                    'product_id' => $linea['detalle']->product_id,
                    'cantidad' => $linea['cantidad'],
                    'importe' => Dinero::pesos($linea['importe']),
                ]);

                // Vuelve al stock; el movimiento viaja al Manager por /sync/movimientos.
                MovimientoStock::registrar($linea['detalle']->product_id, 'devolucion', $linea['cantidad'], $devolucion->numero);
            }

            return $devolucion->load('items');
        });

        SincronizarPendientes::dispatch();

        return $devolucion;
    }
}
