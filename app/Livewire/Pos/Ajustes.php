<?php

namespace App\Livewire\Pos;

use App\Contracts\ImpresoraTickets;
use App\Exceptions\CajaException;
use App\Models\Cajero;
use App\Models\Configuracion;
use App\Services\AutorizacionService;
use App\Services\TicketService;
use App\Services\VentaService;
use Livewire\Component;

/**
 * Ajustes locales de esta caja: impresora de tickets y datos que salen en el ticket.
 * Viven en `configuracion` porque son de la máquina, no del Manager.
 */
class Ajustes extends Component
{
    public string $impresora = '';

    public bool $ticketAutomatico = false;

    public string $nombreComercio = '';

    public string $cuit = '';

    public string $direccion = '';

    public string $pie = '';

    public string $limiteDescuento = '0';

    public string $supervisorId = '';

    public string $supervisorPin = '';

    public ?string $mensaje = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->impresora = (string) Configuracion::get('impresora_ticket', '');
        $this->ticketAutomatico = (bool) Configuracion::get('ticket_automatico', false);
        $this->nombreComercio = (string) Configuracion::get('ticket_nombre_comercio', '');
        $this->cuit = (string) Configuracion::get('ticket_cuit', '');
        $this->direccion = (string) Configuracion::get('ticket_direccion', '');
        $this->pie = (string) Configuracion::get('ticket_pie', '');
        $this->limiteDescuento = (string) VentaService::limiteDescuentoSinAutorizacion();
    }

    public function guardar(ImpresoraTickets $impresoras, AutorizacionService $autorizacion): void
    {
        $this->resetMensajes();

        // Con cajeros cargados, cambiar ajustes lo autoriza un supervisor: si no, cualquier
        // cajero podría subirse el límite de descuento.
        if ($autorizacion->hayCajeros()) {
            try {
                $autorizacion->verificar($this->supervisorId === '' ? null : (int) $this->supervisorId, $this->supervisorPin, requiereSupervisor: true);
            } catch (CajaException $e) {
                $this->error = $e->getMessage();

                return;
            } finally {
                $this->supervisorPin = '';
            }
        }

        $datos = $this->validate([
            'limiteDescuento' => 'required|numeric|min:0|max:100',
            'impresora' => 'nullable|string|max:200',
            'ticketAutomatico' => 'boolean',
            'nombreComercio' => 'nullable|string|max:60',
            'cuit' => ['nullable', 'regex:/^\d{2}-?\d{8}-?\d$/'],
            'direccion' => 'nullable|string|max:80',
            'pie' => 'nullable|string|max:120',
        ], [
            'cuit.regex' => 'El CUIT tiene que tener 11 dígitos (ej: 30-12345678-9).',
        ]);

        if ($datos['ticketAutomatico'] && ! $impresoras->puedeImprimirDirecto()) {
            $this->error = 'La impresión automática solo está disponible en la app de escritorio.';

            return;
        }

        if ($datos['ticketAutomatico'] && trim((string) $datos['impresora']) === '') {
            $this->error = 'Elegí la impresora para imprimir automáticamente.';

            return;
        }

        foreach ([
            'impresora_ticket' => trim((string) $datos['impresora']),
            'ticket_automatico' => $datos['ticketAutomatico'] ? '1' : '0',
            'ticket_nombre_comercio' => trim((string) $datos['nombreComercio']),
            'ticket_cuit' => trim((string) $datos['cuit']),
            'ticket_direccion' => trim((string) $datos['direccion']),
            'ticket_pie' => trim((string) $datos['pie']),
            'descuento_maximo_sin_autorizacion' => (string) (float) $datos['limiteDescuento'],
        ] as $clave => $valor) {
            Configuracion::set($clave, $valor === '' ? null : $valor);
        }

        $this->mensaje = 'Ajustes guardados.';
    }

    public function imprimirPrueba(ImpresoraTickets $impresoras): void
    {
        $this->resetMensajes();

        $html = view('tickets.prueba', ['comercio' => TicketService::datosComercio()])->render();
        $error = $impresoras->imprimir($html, $this->impresora);

        $error === null
            ? $this->mensaje = "Se mandó una prueba a «{$this->impresora}»."
            : $this->error = $error;
    }

    private function resetMensajes(): void
    {
        $this->mensaje = null;
        $this->error = null;
    }

    public function render()
    {
        $impresoras = app(ImpresoraTickets::class);

        return view('livewire.pos.ajustes', [
            'imprimeDirecto' => $impresoras->puedeImprimirDirecto(),
            'supervisores' => Cajero::where('rol', 'supervisor')->orderBy('nombre')->get(['id', 'nombre']),
            'impresorasDisponibles' => $impresoras->puedeImprimirDirecto() ? $impresoras->impresoras() : [],
        ])->layout('layouts.pos');
    }
}
