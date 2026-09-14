<?php

namespace App\Services;

use App\Contracts\ImpresoraTickets;
use App\Models\Cliente;
use App\Models\CobroCuentaCorriente;
use App\Models\Configuracion;
use App\Support\Dinero;
use App\Models\Devolucion;
use App\Models\TurnoCaja;
use App\Models\Venta;

/**
 * Arma el HTML de tickets e informes (80 mm) y los manda a la impresora configurada.
 * El mismo HTML se usa para imprimir directo y para la vista del navegador, así lo que
 * sale por la térmica es lo mismo que se ve en pantalla.
 */
class TicketService
{
    public function __construct(
        private readonly ImpresoraTickets $impresora,
        private readonly CajaService $caja,
    ) {
    }

    public function htmlVenta(Venta $venta, bool $paraNavegador = false): string
    {
        return view('tickets.venta', [
            'venta' => $venta->loadMissing(['detalles.producto', 'pagos']),
            'comercio' => self::datosComercio(),
            // Datos fiscales del emisor tal como los informó el Manager: la factura los usa en
            // vez de los del ticket, así no puede decir otra cosa que lo que se autorizó.
            'emisor' => FacturacionService::emisor(),
            'paraNavegador' => $paraNavegador,
        ])->render();
    }

    public function htmlInforme(TurnoCaja $turno, bool $paraNavegador = false): string
    {
        return view('caja.informe', [
            'turno' => $turno,
            'tipo' => $turno->estaAbierto() ? 'X' : 'Z',
            'resumen' => $turno->estaAbierto() ? $this->caja->resumen($turno) : $turno->resumen,
            'pdv' => Configuracion::get('pdv_nombre'),
            'sucursal' => Configuracion::get('sucursal_nombre'),
            'paraNavegador' => $paraNavegador,
        ])->render();
    }

    public function htmlDevolucion(Devolucion $devolucion, bool $paraNavegador = false): string
    {
        return view('tickets.devolucion', [
            'devolucion' => $devolucion->loadMissing(['items.producto', 'venta']),
            'comercio' => self::datosComercio(),
            'emisor' => FacturacionService::emisor(),
            'paraNavegador' => $paraNavegador,
        ])->render();
    }

    public function htmlCobro(CobroCuentaCorriente $cobro, bool $paraNavegador = false): string
    {
        $cliente = Cliente::find($cobro->cliente_id);

        return view('tickets.cobro', [
            'cobro' => $cobro,
            'comercio' => self::datosComercio(),
            // Saldo después del cobro, calculado en la caja (incluye lo que no se envió).
            'saldo' => $cliente ? Dinero::pesos(app(CuentaCorrienteService::class)->saldoCentavos($cliente)) : null,
            'paraNavegador' => $paraNavegador,
        ])->render();
    }

    /** @return string|null null si se imprimió; si no, el motivo. */
    public function imprimirCobro(CobroCuentaCorriente $cobro): ?string
    {
        return $this->mandar($this->htmlCobro($cobro));
    }

    /** @return string|null null si se imprimió; si no, el motivo. */
    public function imprimirDevolucion(Devolucion $devolucion): ?string
    {
        return $this->mandar($this->htmlDevolucion($devolucion));
    }

    /** @return string|null null si se imprimió; si no, el motivo. */
    public function imprimirVenta(Venta $venta): ?string
    {
        return $this->mandar($this->htmlVenta($venta));
    }

    /** @return string|null null si se imprimió; si no, el motivo. */
    public function imprimirInforme(TurnoCaja $turno): ?string
    {
        return $this->mandar($this->htmlInforme($turno));
    }

    public function imprimeAutomatico(): bool
    {
        return $this->impresora->puedeImprimirDirecto()
            && (bool) Configuracion::get('ticket_automatico', false)
            && (string) Configuracion::get('impresora_ticket', '') !== '';
    }

    private function mandar(string $html): ?string
    {
        if (! $this->impresora->puedeImprimirDirecto()) {
            return 'Esta instalación imprime desde el navegador.';
        }

        return $this->impresora->imprimir($html, (string) Configuracion::get('impresora_ticket', ''));
    }

    /**
     * @return array{nombre: string, cuit: ?string, direccion: ?string, pie: ?string}
     */
    public static function datosComercio(): array
    {
        return [
            'nombre' => (string) (Configuracion::get('ticket_nombre_comercio') ?: Configuracion::get('sucursal_nombre') ?: config('app.name')),
            'cuit' => Configuracion::get('ticket_cuit'),
            'direccion' => Configuracion::get('ticket_direccion'),
            'pie' => Configuracion::get('ticket_pie'),
        ];
    }
}
