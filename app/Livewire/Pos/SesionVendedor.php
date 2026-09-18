<?php

namespace App\Livewire\Pos;

use App\Exceptions\CajaException;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Services\AutorizacionService;
use App\Services\CajaService;
use Livewire\Component;

/**
 * Quién está vendiendo ahora mismo, mostrado en la barra de navegación y exigido antes de
 * poder usar cualquier pantalla. Existe porque el vendedor activo (Configuracion) es
 * global a la instalación y persiste hasta que alguien lo cambie: sin este bloqueo, una
 * caja podía quedar "vendiendo como" quien se identificó la última vez, atribuyéndole
 * ventas ajenas — grave para comisiones. Se resuelve en cada carga de página junto al
 * layout, no dentro de una pantalla puntual, para cubrir toda la app.
 *
 * Minutos de inactividad antes de desloguear solo, para no arrastrar el vendedor
 * equivocado si alguien se olvida de cerrar sesión.
 */
class SesionVendedor extends Component
{
    private const MINUTOS_INACTIVIDAD = 20;

    public string $vendedorSelectId = '';

    public string $pin = '';

    public ?string $error = null;

    public function login(AutorizacionService $autorizacion): void
    {
        $this->error = null;

        try {
            $cajero = $autorizacion->verificar($this->vendedorSelectId === '' ? null : (int) $this->vendedorSelectId, $this->pin);

            Configuracion::set('vendedor_activo_id', (string) $cajero->id);
            Configuracion::set('vendedor_activo_nombre', $cajero->nombre);
            Configuracion::set('vendedor_ultima_actividad', now()->toIso8601String());
            $this->reset(['vendedorSelectId', 'pin']);
        } catch (CajaException $e) {
            $this->error = $e->getMessage();
            $this->pin = '';
        }
    }

    public function logout(): void
    {
        Configuracion::set('vendedor_activo_id', null);
        Configuracion::set('vendedor_activo_nombre', null);
    }

    /**
     * Cualquier click o tecla en la ventana llega acá (con throttle desde la vista), así
     * que esto mide actividad real, no solo que la pestaña siga abierta.
     */
    public function registrarActividad(): void
    {
        if (Configuracion::get('vendedor_activo_id') !== null) {
            Configuracion::set('vendedor_ultima_actividad', now()->toIso8601String());
        }
    }

    public function render()
    {
        $vendedorId = Configuracion::get('vendedor_activo_id');

        if ($vendedorId !== null) {
            $ultimaActividad = Configuracion::get('vendedor_ultima_actividad');

            if ($ultimaActividad && abs(now()->diffInMinutes($ultimaActividad)) >= self::MINUTOS_INACTIVIDAD) {
                $this->logout();
                $vendedorId = null;
            }
        }

        return view('livewire.pos.sesion-vendedor', [
            'vendedorActivoNombre' => $vendedorId !== null ? Configuracion::get('vendedor_activo_nombre') : null,
            'vendedorActivoFotoUrl' => $vendedorId !== null ? Cajero::whereKey((int) $vendedorId)->value('foto_url') : null,
            // Sin turno abierto ya hay un bloqueo propio (Venta: "abrí la caja"), y sin
            // cajeros cargados no hay PIN de nadie que pedir (modo nombre libre): en
            // ninguno de los dos casos tiene sentido superponer este login encima.
            'requiereLogin' => $vendedorId === null
                && app(CajaService::class)->turnoAbierto() !== null
                && app(AutorizacionService::class)->hayCajeros(),
            'cajeros' => Cajero::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
