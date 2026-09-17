<?php

namespace App\Http\Controllers;

use App\Models\CobroCuentaCorriente;
use App\Models\Devolucion;
use App\Models\RemitoSaliente;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\TicketService;
use Illuminate\Http\Response;

/**
 * Versión para el navegador (con diálogo de impresión) de tickets e informes. En la app de
 * escritorio lo normal es imprimir directo; esto queda como alternativa y para la
 * instalación clásica.
 */
class InformeCajaController
{
    /** Informe X si el turno está abierto, Z si está cerrado. */
    public function __invoke(TurnoCaja $turno, TicketService $tickets): Response
    {
        return response($tickets->htmlInforme($turno, paraNavegador: true));
    }

    public function ticket(Venta $venta, TicketService $tickets): Response
    {
        return response($tickets->htmlVenta($venta, paraNavegador: true));
    }

    public function devolucion(Devolucion $devolucion, TicketService $tickets): Response
    {
        return response($tickets->htmlDevolucion($devolucion, paraNavegador: true));
    }

    public function cobro(CobroCuentaCorriente $cobro, TicketService $tickets): Response
    {
        return response($tickets->htmlCobro($cobro, paraNavegador: true));
    }

    public function remito(RemitoSaliente $remito, TicketService $tickets): Response
    {
        return response($tickets->htmlRemito($remito, paraNavegador: true));
    }
}
