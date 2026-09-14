<?php

namespace App\Livewire\Pos;

use App\Contracts\ImpresoraTickets;
use App\Exceptions\CajaException;
use App\Models\Cajero;
use App\Models\Devolucion;
use App\Models\Venta;
use App\Services\AutorizacionService;
use App\Services\DevolucionService;
use App\Services\FacturacionService;
use App\Services\TicketService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ventas de esta caja: consulta, reimpresión de tickets y devoluciones/anulaciones.
 */
class Ventas extends Component
{
    use WithPagination;

    public string $alcance = 'hoy';

    public string $buscar = '';

    #[Locked]
    public ?int $detalleId = null;

    public bool $devolviendo = false;

    /** @var array<int|string, string> detalle_venta_id => unidades */
    public array $cantidades = [];

    public string $motivo = '';

    public string $reintegro = 'efectivo';

    public string $supervisorId = '';

    public string $supervisorPin = '';

    public ?string $mensaje = null;

    public ?string $error = null;

    #[Locked]
    public ?int $ultimaDevolucionId = null;

    public function updating(string $propiedad): void
    {
        if (in_array($propiedad, ['alcance', 'buscar'], true)) {
            $this->resetPage();
        }
    }

    public function verDetalle(int $ventaId): void
    {
        $this->limpiar();
        $this->detalleId = Venta::whereKey($ventaId)->value('id');
        $this->devolviendo = false;
    }

    public function cerrarDetalle(): void
    {
        $this->detalleId = null;
        $this->devolviendo = false;
        $this->reset(['cantidades', 'motivo', 'reintegro', 'supervisorId', 'supervisorPin']);
    }

    public function iniciarDevolucion(): void
    {
        $this->limpiar();
        $this->devolviendo = true;
        $this->cantidades = [];
    }

    /** Anulación: marca para devolver todas las unidades que quedan. */
    public function devolverTodo(): void
    {
        $venta = Venta::with('detalles.devoluciones')->find($this->detalleId);

        $this->cantidades = $venta
            ? $venta->detalles->mapWithKeys(fn ($d) => [$d->id => (string) ($d->cantidad - (int) $d->devoluciones->sum('cantidad'))])->all()
            : [];
    }

    public function registrarDevolucion(AutorizacionService $autorizacion, DevolucionService $devoluciones, TicketService $tickets): void
    {
        $this->limpiar();

        $venta = Venta::find($this->detalleId);

        if (! $venta) {
            return;
        }

        try {
            $supervisor = $autorizacion->verificar($this->supervisorId === '' ? null : (int) $this->supervisorId, $this->supervisorPin, requiereSupervisor: true);
            $devolucion = $devoluciones->registrar($venta, $this->cantidades, $this->motivo, $this->reintegro, $supervisor);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();

            return;
        } finally {
            $this->supervisorPin = '';
        }

        $this->ultimaDevolucionId = $devolucion->id;
        $this->devolviendo = false;
        $this->reset(['cantidades', 'motivo', 'supervisorId']);
        $this->mensaje = ($devolucion->tipo === 'anulacion' ? 'Venta anulada' : 'Devolución registrada')
            ." ({$devolucion->numero}): se reintegran ".\App\Support\Dinero::formato($devolucion->total)
            .($devolucion->reintegro === 'efectivo' ? ' en efectivo.' : ' por el medio de pago original.');

        if ($motivoCajon = app(\App\Services\CajonService::class)->abrirPorEfectivo($devolucion->reintegro === 'efectivo')) {
            $this->error = $motivoCajon;
        }

        if ($tickets->imprimeAutomatico() && ($motivo = $tickets->imprimirDevolucion($devolucion)) !== null) {
            $this->error = "No se imprimió el comprobante: {$motivo}";
        }
    }

    public function imprimirTicket(int $ventaId, TicketService $tickets): void
    {
        $this->limpiar();
        $venta = Venta::find($ventaId);

        if ($venta && ($motivo = $tickets->imprimirVenta($venta)) !== null) {
            $this->error = "No se imprimió: {$motivo}";
        } elseif ($venta) {
            $this->mensaje = "Ticket {$venta->numero_venta} enviado a la impresora.";
        }
    }

    /**
     * Factura que quedó pendiente (sin conexión al cobrar): la pide de nuevo o, si el Manager
     * ya la autorizó en segundo plano, trae el resultado.
     */
    public function facturar(int $ventaId, FacturacionService $facturacion): void
    {
        $this->limpiar();
        $venta = Venta::find($ventaId);

        if (! $venta || $venta->comprobante_estado !== 'pendiente') {
            return;
        }

        $aviso = $venta->sincronizado
            ? (($r = $facturacion->actualizarPendientes())['success'] ? null : $r['error'])
            : $facturacion->facturarAhora($venta);

        $venta->refresh();

        if ($venta->facturaAutorizada()) {
            $this->mensaje = "{$venta->comprobante['nombre_tipo']} {$venta->comprobante['numero']} autorizada.";
        } else {
            $this->error = $aviso ?? ($venta->comprobante_estado === 'rechazado'
                ? 'AFIP rechazó la factura: '.($venta->comprobante['error'] ?? 'sin detalle')
                : 'La factura sigue pendiente en el Manager (AFIP todavía no la autorizó).');
        }
    }

    public function imprimirDevolucion(int $devolucionId, TicketService $tickets): void
    {
        $this->limpiar();
        $devolucion = Devolucion::find($devolucionId);

        if ($devolucion && ($motivo = $tickets->imprimirDevolucion($devolucion)) !== null) {
            $this->error = "No se imprimió: {$motivo}";
        }
    }

    private function limpiar(): void
    {
        $this->mensaje = null;
        $this->error = null;
    }

    public function render()
    {
        $ventas = Venta::query()
            ->with(['pagos'])
            ->withSum('devoluciones as devuelto', 'total')
            ->when($this->alcance === 'turno', fn ($q) => $q->where('turno_caja_id', app(\App\Services\CajaService::class)->turnoAbierto()?->id ?? 0))
            ->when($this->alcance === 'hoy', fn ($q) => $q->where('fecha', '>=', now()->timezone(config('pos.zona_horaria'))->startOfDay()->utc()))
            ->when($this->alcance === '7dias', fn ($q) => $q->where('fecha', '>=', now()->subDays(7)))
            ->when(trim($this->buscar) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('numero_venta', 'like', '%'.trim($this->buscar).'%')
                ->orWhere('cliente_nombre', 'like', '%'.trim($this->buscar).'%')
                ->orWhere('cliente_documento', 'like', '%'.trim($this->buscar).'%')))
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.pos.ventas', [
            'ventas' => $ventas,
            'detalle' => $this->detalleId ? Venta::with(['detalles.producto', 'detalles.devoluciones', 'pagos', 'devoluciones'])->find($this->detalleId) : null,
            'supervisores' => $this->devolviendo ? Cajero::where('rol', 'supervisor')->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'imprimeDirecto' => app(ImpresoraTickets::class)->puedeImprimirDirecto(),
            'devolucionesService' => app(DevolucionService::class),
        ])->layout('layouts.pos');
    }
}
