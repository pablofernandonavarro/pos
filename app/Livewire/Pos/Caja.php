<?php

namespace App\Livewire\Pos;

use App\Exceptions\CajaException;
use App\Models\TurnoCaja;
use App\Contracts\ImpresoraTickets;
use App\Services\CajaService;
use App\Services\TicketService;
use App\Support\Dinero;
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
        $turno = $caja->turnoAbierto();
        $resumen = $turno ? $caja->resumen($turno) : null;

        return view('livewire.pos.caja', [
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
