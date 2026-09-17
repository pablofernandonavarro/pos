<?php

namespace App\Livewire\Pos;

use App\Contracts\ImpresoraTickets;
use App\Models\RemitoSaliente;
use App\Models\Sucursal;
use App\Services\RemitosSalientesService;
use App\Services\TicketService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Historial de remitos que esta caja mandó a otras sucursales, con filtros y reimpresión.
 * Se lee de la copia local (RemitosSalientesService::sincronizar), que trae de nuevo el
 * estado real desde el Manager: esta pantalla no es solo lo que la caja recuerda haber
 * creado, sino si ya lo recibieron o sigue en camino.
 */
class RemitosEnviados extends Component
{
    use WithPagination;

    public string $sucursalId = '';

    public string $estado = '';

    public ?string $mensaje = null;

    public ?string $error = null;

    public function mount(RemitosSalientesService $remitos): void
    {
        $resultado = $remitos->sincronizar();

        if (! $resultado['success']) {
            $this->error = 'No se pudo actualizar el estado desde el Manager: '.($resultado['error'] ?? 'error desconocido').'. Se muestra lo último que se sincronizó.';
        }
    }

    public function actualizar(RemitosSalientesService $remitos): void
    {
        $this->mensaje = null;
        $this->error = null;

        $resultado = $remitos->sincronizar();

        if (! $resultado['success']) {
            $this->error = 'No se pudo consultar el Manager: '.($resultado['error'] ?? 'error desconocido');
        }
    }

    public function updatingSucursalId(): void
    {
        $this->resetPage();
    }

    public function updatingEstado(): void
    {
        $this->resetPage();
    }

    public function imprimir(int $remitoId, TicketService $tickets): void
    {
        $this->mensaje = null;
        $this->error = null;

        $remito = RemitoSaliente::find($remitoId);

        if (! $remito) {
            return;
        }

        $motivo = $tickets->imprimirRemito($remito);

        if ($motivo !== null) {
            $this->error = "No se imprimió: {$motivo}";
        } else {
            $this->mensaje = "Remito {$remito->numero} enviado a la impresora.";
        }
    }

    #[Layout('layouts.pos')]
    public function render()
    {
        $remitos = RemitoSaliente::query()
            ->when($this->sucursalId, fn ($q) => $q->where('destino_sucursal_id', $this->sucursalId))
            ->when($this->estado, fn ($q) => $q->where('estado', $this->estado))
            ->orderByDesc('enviado_at')
            ->paginate(15);

        return view('livewire.pos.remitos-enviados', [
            'remitos' => $remitos,
            // Sucursales a las que esta caja ya mandó algo, no todas: no tiene sentido
            // ofrecer un filtro que siempre da vacío.
            'sucursales' => Sucursal::whereIn('id', RemitoSaliente::select('destino_sucursal_id')->distinct())->orderBy('nombre')->get(),
            'imprimeDirecto' => app(ImpresoraTickets::class)->puedeImprimirDirecto(),
        ]);
    }
}
