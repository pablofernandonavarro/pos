<?php

namespace App\Livewire\Pos;

use App\Exceptions\CajaException;
use App\Models\Cliente;
use App\Models\TurnoCaja;
use App\Contracts\ImpresoraTickets;
use App\Services\CajaService;
use App\Services\CuentaCorrienteService;
use App\Services\TicketService;
use App\Support\Dinero;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Caja del turno: informe X en vivo, movimientos de efectivo, cierre Z con arqueo e
 * historial de cierres para reimprimir.
 */
class Caja extends Component
{
    public string $movimientoTipo = 'retiro';

    public string $movimientoMonto = '';

    public string $movimientoMotivo = '';

    public bool $cerrando = false;

    public string $efectivoContado = '';

    public string $observaciones = '';

    public ?string $error = null;

    public ?string $exito = null;

    /** Turno recién cerrado, para ofrecer imprimir el Z. */
    public ?int $cerradoId = null;

    // --- Cobro de cuenta corriente ---
    public string $clienteBusquedaCobro = '';

    #[Locked]
    public ?int $clienteCobroId = null;

    public string $cobroMonto = '';

    public string $cobroMedio = 'efectivo';

    #[Locked]
    public ?int $ultimoCobroId = null;

    public function elegirClienteCobro(int $id): void
    {
        $this->limpiar();
        $this->clienteCobroId = Cliente::whereKey($id)->value('id');
        $this->reset(['clienteBusquedaCobro', 'cobroMonto', 'cobroMedio']);
    }

    public function quitarClienteCobro(): void
    {
        $this->clienteCobroId = null;
        $this->reset(['clienteBusquedaCobro', 'cobroMonto', 'cobroMedio']);
    }

    public function cobrarCuenta(CajaService $caja, CuentaCorrienteService $cuentas, TicketService $tickets): void
    {
        $this->limpiar();

        $turno = $caja->turnoAbierto();
        $cliente = $this->clienteCobroId ? Cliente::find($this->clienteCobroId) : null;

        if (! $turno || ! $cliente) {
            $this->error = ! $turno ? 'La caja está cerrada.' : 'Elegí el cliente.';

            return;
        }

        try {
            $cobro = $cuentas->cobrar($cliente, $this->cobroMonto === '' ? 0 : $this->cobroMonto, $this->cobroMedio, $turno);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->ultimoCobroId = $cobro->id;
        $this->exito = "Cobro {$cobro->numero}: ".Dinero::formato($cobro->importe)." de {$cliente->nombre}. Debe ".Dinero::formato(Dinero::pesos($cuentas->saldoCentavos($cliente))).'.';
        $this->reset(['cobroMonto', 'cobroMedio']);

        if ($tickets->imprimeAutomatico() && ($motivo = $tickets->imprimirCobro($cobro)) !== null) {
            $this->error = "No se imprimió el recibo: {$motivo}";
        }
    }

    public function registrarMovimiento(CajaService $caja): void
    {
        $this->limpiar();

        $turno = $caja->turnoAbierto();

        if (! $turno) {
            $this->error = 'La caja está cerrada.';

            return;
        }

        try {
            $movimiento = $caja->registrarMovimiento($turno, $this->movimientoTipo, $this->movimientoMonto === '' ? 0 : $this->movimientoMonto, $this->movimientoMotivo);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->exito = ucfirst($movimiento->tipo).' de '.Dinero::formato($movimiento->monto).' registrado.';
        $this->reset(['movimientoMonto', 'movimientoMotivo']);
    }

    public function iniciarCierre(): void
    {
        $this->limpiar();
        $this->cerrando = true;
    }

    public function cancelarCierre(): void
    {
        $this->cerrando = false;
        $this->reset(['efectivoContado', 'observaciones']);
    }

    public function cerrarCaja(CajaService $caja): void
    {
        $this->limpiar();

        $turno = $caja->turnoAbierto();

        if (! $turno) {
            $this->error = 'La caja ya está cerrada.';

            return;
        }

        if ($this->efectivoContado === '') {
            $this->error = 'Contá el efectivo del cajón y cargalo antes de cerrar.';

            return;
        }

        try {
            $cerrado = $caja->cerrar($turno, $this->efectivoContado, $this->observaciones);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $diferencia = (float) $cerrado->resumen['efectivo']['diferencia'];

        $this->cerrando = false;
        $this->cerradoId = $cerrado->id;
        $this->reset(['efectivoContado', 'observaciones']);
        $this->exito = "Cierre Z #{$cerrado->numero} realizado. "
            .match (true) {
                $diferencia < 0 => 'Faltante de '.Dinero::formato(abs($diferencia)).'.',
                $diferencia > 0 => 'Sobrante de '.Dinero::formato($diferencia).'.',
                default => 'Sin diferencias.',
            };
    }

    public function imprimirInforme(int $turnoId, TicketService $tickets): void
    {
        $this->limpiar();

        $turno = TurnoCaja::find($turnoId);

        if (! $turno) {
            return;
        }

        $motivo = $tickets->imprimirInforme($turno);

        $motivo === null
            ? $this->exito = 'Informe '.($turno->estaAbierto() ? 'X' : 'Z')." #{$turno->numero} enviado a la impresora."
            : $this->error = "No se imprimió: {$motivo}";
    }

    private function limpiar(): void
    {
        $this->error = null;
        $this->exito = null;
    }

    public function render()
    {
        $caja = app(CajaService::class);
        $cuentas = app(CuentaCorrienteService::class);
        $turno = $caja->turnoAbierto();
        $resumen = $turno ? $caja->resumen($turno) : null;
        $clienteCobro = $this->clienteCobroId ? Cliente::find($this->clienteCobroId) : null;

        return view('livewire.pos.caja', [
            'clienteCobro' => $clienteCobro,
            'saldoCobro' => $clienteCobro ? Dinero::pesos($cuentas->saldoCentavos($clienteCobro)) : 0,
            'resultadosCobro' => $clienteCobro ? collect() : $cuentas->buscar($this->clienteBusquedaCobro),
            'turno' => $turno,
            'resumen' => $resumen,
            'imprimeDirecto' => app(ImpresoraTickets::class)->puedeImprimirDirecto(),
            'diferenciaPrevia' => $resumen && $this->efectivoContado !== ''
                ? Dinero::pesos(Dinero::centavos($this->efectivoContado) - Dinero::centavos($resumen['efectivo']['esperado']))
                : null,
            'cierres' => TurnoCaja::whereNotNull('cerrado_at')->latest('cerrado_at')->limit(15)->get(),
        ])->layout('layouts.pos');
    }
}
