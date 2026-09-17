<?php

namespace App\Livewire\Pos;

use App\Contracts\ImpresoraTickets;
use App\Models\RemitoSaliente;
use App\Services\TicketService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Historial de remitos que esta caja mandó a otras sucursales, con reimpresión. Se lee de
 * la copia local (ver RemitosSalientesService::guardarCopiaLocal): el Manager es la fuente
 * de verdad para el stock, pero esta pantalla no necesita ida y vuelta a la red.
 */
class RemitosEnviados extends Component
{
    use WithPagination;

    public ?string $mensaje = null;

    public ?string $error = null;

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
        return view('livewire.pos.remitos-enviados', [
            'remitos' => RemitoSaliente::orderByDesc('enviado_at')->paginate(15),
            'imprimeDirecto' => app(ImpresoraTickets::class)->puedeImprimirDirecto(),
        ]);
    }
}
