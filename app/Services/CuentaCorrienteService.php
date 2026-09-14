<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Jobs\SincronizarPendientes;
use App\Models\Cliente;
use App\Models\CobroCuentaCorriente;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\PagoVenta;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta corriente vista desde la caja: saldo, límite, venta a cuenta y cobro de deuda.
 *
 * El saldo tiene que ser correcto sin conexión. Se parte del saldo que informó el Manager
 * y se le suma lo que la caja movió y el Manager todavía no incluía cuando armó esa lista:
 * lo no enviado, y lo enviado **después** de pedirla (`clientes.sincronizado_at` es la hora
 * de la caja al pedir, así no dependemos de que los relojes coincidan).
 *
 * Mismo criterio que el Manager (`CuentaCorrienteService` de allá): una devolución al medio
 * original acredita como mucho lo que esa venta cargó a cuenta.
 */
class CuentaCorrienteService
{
    /** Saldo del cliente en centavos (positivo = debe). */
    public function saldoCentavos(Cliente $cliente): int
    {
        $corte = $cliente->sincronizado_at;
        $noIncluido = fn ($q) => $q->where('sincronizado', false)
            ->when($corte, fn ($w) => $w->orWhere('sincronizado_at', '>', $corte));

        $ventas = PagoVenta::query()
            ->where('medio', 'cuenta_corriente')
            ->whereHas('venta', fn ($q) => $q->where('cliente_id', $cliente->id)->where($noIncluido))
            ->sum('importe');

        $cobros = CobroCuentaCorriente::where('cliente_id', $cliente->id)->where($noIncluido)->sum('importe');

        $devoluciones = Devolucion::with('venta.pagos')
            ->where('reintegro', 'medio_original')
            ->whereHas('venta', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->where($noIncluido)
            ->get()
            ->sum(fn (Devolucion $d) => $this->creditoCentavos($d));

        return Dinero::centavos($cliente->saldo) + Dinero::centavos($ventas) - Dinero::centavos($cobros) - $devoluciones;
    }

    /** Lo que todavía puede comprar a cuenta, en centavos. null = sin límite. */
    public function disponibleCentavos(Cliente $cliente): ?int
    {
        if ($cliente->limite_credito === null) {
            return null;
        }

        return max(0, Dinero::centavos($cliente->limite_credito) - $this->saldoCentavos($cliente));
    }

    /** Valida cargar un importe a la cuenta del cliente. */
    public function validarVentaACuenta(?Cliente $cliente, int $centavos): void
    {
        if ($centavos <= 0) {
            return;
        }

        if (! $cliente) {
            throw new CajaException('Para vender a cuenta corriente elegí el cliente.');
        }

        if (! $cliente->cuenta_corriente) {
            throw new CajaException("{$cliente->nombre} no tiene cuenta corriente habilitada.");
        }

        $disponible = $this->disponibleCentavos($cliente);

        if ($disponible !== null && $centavos > $disponible) {
            throw new CajaException("Supera el límite de crédito de {$cliente->nombre}: disponible ".Dinero::formato(Dinero::pesos($disponible)).'.');
        }
    }

    /**
     * Crédito de una devolución a la cuenta, en centavos: lo devuelto, como mucho lo que la
     * venta cargó a cuenta menos lo que ya acreditaron devoluciones anteriores.
     */
    public function creditoCentavos(Devolucion $devolucion): int
    {
        if ($devolucion->reintegro !== 'medio_original') {
            return 0;
        }

        $venta = $devolucion->venta;
        $cargado = Dinero::centavos($venta->pagos->where('medio', 'cuenta_corriente')->sum('importe'));

        if ($cargado === 0) {
            return 0;
        }

        $anteriores = Devolucion::where('venta_id', $venta->id)
            ->where('reintegro', 'medio_original')
            ->where('id', '<', $devolucion->id)
            ->sum('total');

        return max(0, min(Dinero::centavos($devolucion->total), $cargado - Dinero::centavos($anteriores)));
    }

    /** Cobro de deuda en la caja. */
    public function cobrar(Cliente $cliente, float|int|string $monto, string $medio, TurnoCaja $turno): CobroCuentaCorriente
    {
        $centavos = Dinero::centavos($monto);

        if (! $turno->estaAbierto()) {
            throw new CajaException('La caja está cerrada.');
        }

        if (! in_array($medio, CobroCuentaCorriente::MEDIOS, true)) {
            throw new CajaException('Elegí cómo paga.');
        }

        if ($centavos <= 0) {
            throw new CajaException('El importe tiene que ser mayor a cero.');
        }

        $saldo = $this->saldoCentavos($cliente);

        if ($saldo <= 0) {
            throw new CajaException("{$cliente->nombre} no tiene deuda.");
        }

        if ($centavos > $saldo) {
            throw new CajaException('El cobro supera la deuda ('.Dinero::formato(Dinero::pesos($saldo)).').');
        }

        $cobro = DB::transaction(fn () => CobroCuentaCorriente::create([
            'numero' => sprintf('COB%02d-%06d', (int) Configuracion::get('punto_de_venta_id', 0), (int) CobroCuentaCorriente::max('id') + 1),
            'cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'turno_caja_id' => $turno->id,
            'medio' => $medio,
            'importe' => Dinero::pesos($centavos),
            'cajero' => $turno->cajero,
        ]));

        SincronizarPendientes::dispatch();

        return $cobro;
    }

    /** Clientes que coinciden con lo que se tipea (nombre, CUIT o DNI). */
    public function buscar(string $texto, int $limite = 8): \Illuminate\Support\Collection
    {
        if (mb_strlen(trim($texto)) < 2) {
            return collect();
        }

        return Cliente::buscar($texto)->orderBy('nombre')->limit($limite)->get();
    }

    /** Ventas del turno cargadas a cuenta, para el informe. */
    public function ventasACuentaDelTurno(TurnoCaja $turno): int
    {
        return Dinero::centavos(PagoVenta::where('medio', 'cuenta_corriente')
            ->whereHas('venta', fn ($q) => $q->where('turno_caja_id', $turno->id))
            ->sum('importe'));
    }
}
