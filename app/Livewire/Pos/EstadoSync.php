<?php

namespace App\Livewire\Pos;

use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\MovimientoStock;
use App\Models\RemitoEntrante;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\SyncService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

class EstadoSync extends Component
{
    public int $pendientes = 0;

    public int $remitosPorRecibir = 0;

    /** Hora local del último contacto exitoso, si fue hace demasiado. Null = conectada. */
    public ?string $sinConexionDesde = null;

    public bool $sincronizando = false;

    public ?string $error = null;

    public function mount(): void
    {
        $this->contarPendientes();
    }

    /**
     * Solo lee SQLite local, así que el poll del header no toca la red ni puede
     * colgar la pantalla de venta si el Manager está caído.
     */
    #[On('venta-finalizada')]
    #[On('remitos-actualizados')]
    public function contarPendientes(): void
    {
        // El turno abierto no cuenta: se reenvía siempre y no es algo "pendiente" de la caja.
        $this->pendientes = Venta::pendientes()->count()
            + MovimientoStock::pendientes()->where('tipo', '!=', 'venta')->count()
            + TurnoCaja::pendientes()->whereNotNull('cerrado_at')->count()
            + Devolucion::pendientes()->count();

        // La copia local la mantiene el scheduler cada minuto (pos:sync --stock).
        $this->remitosPorRecibir = RemitoEntrante::count();

        // El pull de stock corre cada minuto y solo anota la hora si le fue bien: si hace
        // varios minutos que no la anota, la caja no está llegando al Manager. Se deduce
        // de SQLite para no tocar la red desde la pantalla.
        $ultimoContacto = Configuracion::get('ultima_sincronizacion_stock');
        $this->sinConexionDesde = $ultimoContacto && Carbon::parse($ultimoContacto)->lt(now()->subMinutes(4))
            ? Carbon::parse($ultimoContacto)->timezone(config('pos.zona_horaria'))->format('H:i')
            : null;
    }

    public function sincronizarAhora(): void
    {
        $this->sincronizando = true;
        $this->error = null;

        try {
            $sync = app(SyncService::class);

            $ventas = $sync->pushVentas();
            $movimientos = $sync->pushMovimientos();
            $devoluciones = $sync->pushDevoluciones();
            $turnos = $sync->pushTurnos();

            if (! $ventas['success']) {
                $this->error = $ventas['error'] ?? 'No se pudieron enviar las ventas';
            } elseif (! $movimientos['success']) {
                $this->error = $movimientos['error'] ?? 'No se pudieron enviar los movimientos';
            } elseif (! $devoluciones['success']) {
                $this->error = $devoluciones['error'] ?? 'No se pudieron enviar las devoluciones';
            } elseif (! $turnos['success']) {
                $this->error = $turnos['error'] ?? 'No se pudieron enviar los cierres de caja';
            }
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->sincronizando = false;
            $this->contarPendientes();
        }
    }

    public function render()
    {
        return view('livewire.pos.estado-sync', [
            'ultimaSync' => Configuracion::get('ultima_sincronizacion_productos'),
        ]);
    }
}
