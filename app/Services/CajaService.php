<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\AperturaCajon;
use App\Models\CobroCuentaCorriente;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\TurnoCaja;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Turno de caja: apertura, movimientos de efectivo, informe X (resumen en vivo) y
 * cierre Z con arqueo. Todas las cuentas en centavos (ver App\Support\Dinero).
 */
class CajaService
{
    public function turnoAbierto(): ?TurnoCaja
    {
        return TurnoCaja::abierto()->latest('id')->first();
    }

    /**
     * Apertura identificando al cajero con su PIN. Es el camino obligatorio cuando el
     * Manager tiene cajeros cargados para esta sucursal.
     */
    public function abrirConPin(?int $cajeroId, string $pin, float|int|string $fondoInicial): TurnoCaja
    {
        $cajero = app(AutorizacionService::class)->verificar($cajeroId, $pin);

        return $this->crearTurno($cajero->nombre, $fondoInicial, $cajero->id);
    }

    /**
     * Apertura con nombre libre: solo mientras no haya cajeros configurados (instalaciones
     * que todavía no los cargaron en el Manager).
     */
    public function abrir(string $cajero, float|int|string $fondoInicial): TurnoCaja
    {
        if (app(AutorizacionService::class)->hayCajeros()) {
            throw new CajaException('Elegí tu usuario de cajero e ingresá el PIN para abrir la caja.');
        }

        return $this->crearTurno($cajero, $fondoInicial);
    }

    private function crearTurno(string $cajero, float|int|string $fondoInicial, ?int $cajeroId = null): TurnoCaja
    {
        $cajero = trim($cajero);
        $fondo = Dinero::centavos($fondoInicial);

        if ($cajero === '') {
            throw new CajaException('Indicá quién abre la caja.');
        }

        if ($fondo < 0) {
            throw new CajaException('El fondo inicial no puede ser negativo.');
        }

        return DB::transaction(function () use ($cajero, $fondo, $cajeroId) {
            if ($abierto = $this->turnoAbierto()) {
                throw new CajaException("La caja ya está abierta (turno #{$abierto->numero}, {$abierto->cajero}).");
            }

            return TurnoCaja::create([
                'numero' => (int) TurnoCaja::max('numero') + 1,
                'cajero' => mb_substr($cajero, 0, 100),
                'cajero_id' => $cajeroId,
                'fondo_inicial' => Dinero::pesos($fondo),
                'abierto_at' => now(),
            ]);
        });
    }

    public function registrarMovimiento(TurnoCaja $turno, string $tipo, float|int|string $monto, string $motivo): MovimientoCaja
    {
        $centavos = Dinero::centavos($monto);
        $motivo = trim($motivo);

        if (! $turno->estaAbierto()) {
            throw new CajaException('El turno ya está cerrado.');
        }

        if (! array_key_exists($tipo, MovimientoCaja::TIPOS)) {
            throw new CajaException('Tipo de movimiento inválido.');
        }

        if ($centavos <= 0) {
            throw new CajaException('El monto tiene que ser mayor a cero.');
        }

        if ($motivo === '') {
            throw new CajaException('Indicá el motivo del movimiento.');
        }

        // No se puede sacar de la caja más efectivo del que debería haber.
        if ($tipo !== 'ingreso') {
            $disponible = Dinero::centavos($this->resumen($turno)['efectivo']['esperado']);

            if ($centavos > $disponible) {
                throw new CajaException('No hay tanto efectivo en la caja: debería haber '.Dinero::formato(Dinero::pesos($disponible)).'.');
            }
        }

        return $turno->movimientos()->create([
            'tipo' => $tipo,
            'monto' => Dinero::pesos($centavos),
            'motivo' => mb_substr($motivo, 0, 200),
        ]);
    }

    /**
     * Informe X: el estado del turno en este momento. Al cerrar, esto mismo queda
     * guardado como cierre Z.
     *
     * @return array<string, mixed>
     */
    public function resumen(TurnoCaja $turno): array
    {
        $ventas = $turno->ventas()->with(['pagos', 'detalles'])->orderBy('id')->get();

        $subtotal = $descuentos = $descuentosManuales = $total = $unidades = 0;
        $porMedio = [];
        $tarjetas = [];
        $promociones = [];

        foreach ($ventas as $venta) {
            $subtotal += Dinero::centavos($venta->subtotal);
            $descuentos += Dinero::centavos($venta->descuento);
            $descuentosManuales += Dinero::centavos($venta->descuento_manual);
            $total += Dinero::centavos($venta->total);
            $unidades += (int) $venta->detalles->sum('cantidad');

            foreach ($venta->pagos as $pago) {
                $importe = Dinero::centavos($pago->importe);
                $porMedio[$pago->medio] = ($porMedio[$pago->medio] ?? 0) + $importe;

                if (in_array($pago->medio, PagoVenta::CON_TARJETA, true)) {
                    $clave = implode('|', [$pago->medio, $pago->tarjeta, $pago->cuotas]);
                    $tarjetas[$clave] ??= ['medio' => $pago->medio, 'tarjeta' => $pago->tarjeta, 'cuotas' => $pago->cuotas, 'cantidad' => 0, 'importe' => 0];
                    $tarjetas[$clave]['cantidad']++;
                    $tarjetas[$clave]['importe'] += $importe;
                }

                if ($pago->promocion_nombre) {
                    $promociones[$pago->promocion_nombre] ??= ['nombre' => $pago->promocion_nombre, 'cantidad' => 0, 'descuento' => 0];
                    $promociones[$pago->promocion_nombre]['cantidad']++;
                    $promociones[$pago->promocion_nombre]['descuento'] += Dinero::centavos($pago->descuento);
                }
            }
        }

        $movimientos = $turno->movimientos()->orderBy('id')->get();
        $sumaMovimientos = fn (string $tipo) => $movimientos->where('tipo', $tipo)->sum(fn ($m) => Dinero::centavos($m->monto));

        // Las devoluciones son del turno en que se hicieron, aunque la venta sea de otro día:
        // la plata sale del cajón de este turno.
        $devoluciones = $turno->devoluciones()->with('venta')->orderBy('id')->get();
        $devueltoTotal = $devoluciones->sum(fn ($d) => Dinero::centavos($d->total));
        $devueltoEfectivo = $devoluciones->where('reintegro', 'efectivo')->sum(fn ($d) => Dinero::centavos($d->total));

        // Cobros de deudas de cuenta corriente: no son ventas del turno, pero la plata entra.
        $cobros = CobroCuentaCorriente::where('turno_caja_id', $turno->id)->orderBy('id')->get();
        $cobrosPorMedio = [];

        foreach ($cobros as $cobro) {
            $cobrosPorMedio[$cobro->medio] = ($cobrosPorMedio[$cobro->medio] ?? 0) + Dinero::centavos($cobro->importe);
        }

        $cobrosEfectivo = $cobrosPorMedio['efectivo'] ?? 0;

        $efectivoVentas = $porMedio['efectivo'] ?? 0;
        $ingresos = $sumaMovimientos('ingreso');
        $retiros = $sumaMovimientos('retiro');
        $gastos = $sumaMovimientos('gasto');
        $esperado = Dinero::centavos($turno->fondo_inicial) + $efectivoVentas + $cobrosEfectivo + $ingresos - $retiros - $gastos - $devueltoEfectivo;

        $pesos = fn (int $c) => Dinero::pesos($c);

        ksort($porMedio);

        return [
            'turno' => [
                'numero' => $turno->numero,
                'cajero' => $turno->cajero,
                'abierto_at' => $turno->abierto_at?->toIso8601String(),
                'cerrado_at' => $turno->cerrado_at?->toIso8601String(),
            ],
            'ventas' => [
                'cantidad' => $ventas->count(),
                'unidades' => $unidades,
                'subtotal' => $pesos($subtotal),
                'descuentos' => $pesos($descuentos),
                'descuentos_manuales' => $pesos($descuentosManuales),
                'total' => $pesos($total),
                'neto' => $pesos($total - $devueltoTotal),
                'ticket_promedio' => $ventas->isEmpty() ? 0.0 : $pesos(intdiv($total, $ventas->count())),
            ],
            'devoluciones' => [
                'cantidad' => $devoluciones->count(),
                'total' => $pesos($devueltoTotal),
                'efectivo' => $pesos($devueltoEfectivo),
                'medio_original' => $pesos($devueltoTotal - $devueltoEfectivo),
                'detalle' => $devoluciones->map(fn ($d) => [
                    'numero' => $d->numero,
                    'venta' => $d->venta?->numero_venta,
                    'tipo' => $d->tipo,
                    'reintegro' => $d->reintegro,
                    'total' => (float) $d->total,
                    'motivo' => $d->motivo,
                    'autorizado_por' => $d->autorizado_por,
                ])->all(),
            ],
            'por_medio' => array_map($pesos, $porMedio),
            'cuenta_corriente' => [
                'ventas' => $pesos($porMedio['cuenta_corriente'] ?? 0),
                'cobros' => $pesos(array_sum($cobrosPorMedio)),
                'cobros_por_medio' => array_map($pesos, $cobrosPorMedio),
                'detalle' => $cobros->map(fn (CobroCuentaCorriente $c) => [
                    'numero' => $c->numero,
                    'cliente' => $c->cliente_nombre,
                    'medio' => $c->medio,
                    'importe' => (float) $c->importe,
                ])->all(),
            ],
            'tarjetas' => array_values(array_map(fn ($t) => [...$t, 'importe' => $pesos($t['importe'])], $tarjetas)),
            'promociones' => array_values(array_map(fn ($p) => [...$p, 'descuento' => $pesos($p['descuento'])], $promociones)),
            'efectivo' => [
                'fondo_inicial' => $pesos(Dinero::centavos($turno->fondo_inicial)),
                'ventas' => $pesos($efectivoVentas),
                'cobros_cuenta_corriente' => $pesos($cobrosEfectivo),
                'ingresos' => $pesos($ingresos),
                'retiros' => $pesos($retiros),
                'gastos' => $pesos($gastos),
                'devoluciones' => $pesos($devueltoEfectivo),
                'esperado' => $pesos($esperado),
            ],
            'aperturas_cajon' => AperturaCajon::where('turno_caja_id', $turno->id)->orderBy('id')->get()
                ->map(fn (AperturaCajon $a) => [
                    'motivo' => $a->motivo,
                    'cajero' => $a->cajero,
                    'abrio' => $a->abrio,
                    'fecha' => $a->created_at->toIso8601String(),
                ])->all(),
            'movimientos' => $movimientos->map(fn (MovimientoCaja $m) => [
                'tipo' => $m->tipo,
                'monto' => (float) $m->monto,
                'motivo' => $m->motivo,
                'fecha' => $m->created_at->toIso8601String(),
            ])->all(),
            'numeracion' => [
                'desde' => $ventas->first()?->numero_venta,
                'hasta' => $ventas->last()?->numero_venta,
            ],
        ];
    }

    /**
     * Cierre Z: congela el resumen con el arqueo. Después de esto el turno no cambia más.
     */
    public function cerrar(TurnoCaja $turno, float|int|string $efectivoContado, ?string $observaciones = null): TurnoCaja
    {
        $contado = Dinero::centavos($efectivoContado);

        if ($contado < 0) {
            throw new CajaException('El efectivo contado no puede ser negativo.');
        }

        $turno = DB::transaction(function () use ($turno, $contado, $observaciones) {
            $turno = TurnoCaja::lockForUpdate()->findOrFail($turno->id);

            if (! $turno->estaAbierto()) {
                throw new CajaException("El turno #{$turno->numero} ya está cerrado.");
            }

            $turno->cerrado_at = now();
            $resumen = $this->resumen($turno);
            $esperado = Dinero::centavos($resumen['efectivo']['esperado']);

            $resumen['efectivo']['contado'] = Dinero::pesos($contado);
            $resumen['efectivo']['diferencia'] = Dinero::pesos($contado - $esperado);

            $turno->fill([
                'efectivo_contado' => Dinero::pesos($contado),
                'resumen' => $resumen,
                'observaciones' => ($o = trim((string) $observaciones)) === '' ? null : mb_substr($o, 0, 1000),
            ])->save();

            return $turno;
        });

        // Fuera de la transacción, como con las ventas: el Manager recibe el Z en segundos.
        SincronizarPendientes::dispatch();

        return $turno;
    }
}
