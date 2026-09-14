@php
    use App\Models\Devolucion;
    use App\Support\Dinero;
    $zona = config('pos.zona_horaria');
    $fiscal = $devolucion->notaDeCreditoAutorizada();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $devolucion->numero }}</title>
    <style>
        @page { size: 80mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; color: #000; margin: 0 auto; width: 72mm; }
        .centro { text-align: center; }
        .grande { font-size: 15px; font-weight: bold; }
        .sep { border-top: 1px dashed #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .chico { font-size: 11px; }
        .fuerte { font-weight: bold; }
        .qr svg { width: 32mm; height: 32mm; margin: 4px auto; display: block; }
        .no-imprimir { margin: 10px 0; text-align: center; }
        @media print { .no-imprimir { display: none; } }
    </style>
</head>
<body @if($paraNavegador) onload="window.print()" @endif>
    @if($paraNavegador)
        <div class="no-imprimir"><button onclick="window.print()">Imprimir</button> <button onclick="window.close()">Cerrar</button></div>
    @endif

    @if($fiscal)
        @include('tickets._fiscal-encabezado', ['comprobante' => $devolucion->comprobante])
    @else
        <div class="centro grande">{{ $comercio['nombre'] }}</div>
        @if($comercio['cuit'])<div class="centro chico">CUIT {{ $comercio['cuit'] }}</div>@endif
        <div class="sep"></div>
    @endif
    <div class="centro grande">{{ $devolucion->tipo === 'anulacion' ? 'ANULACIÓN' : 'DEVOLUCIÓN' }}</div>
    <table>
        <tr><td>Comprobante</td><td class="num">{{ $devolucion->numero }}</td></tr>
        <tr><td>Venta</td><td class="num">{{ $devolucion->venta->numero_venta }}</td></tr>
        <tr><td>Fecha</td><td class="num">{{ $devolucion->created_at->timezone($zona)->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Autorizó</td><td class="num">{{ $devolucion->autorizado_por }}</td></tr>
    </table>
    <div class="sep"></div>
    <table>
        @foreach($devolucion->items as $item)
            <tr><td colspan="2">{{ $item->producto?->nombre ?? 'Artículo '.$item->product_id }}</td></tr>
            <tr class="chico"><td>{{ $item->cantidad }} u.</td><td class="num">{{ Dinero::formato($item->importe) }}</td></tr>
        @endforeach
    </table>
    <div class="sep"></div>
    <table>
        <tr class="grande"><td>REINTEGRO</td><td class="num">{{ Dinero::formato($devolucion->total) }}</td></tr>
        <tr class="chico"><td colspan="2">{{ Devolucion::REINTEGROS[$devolucion->reintegro] }}</td></tr>
    </table>
    <div class="sep"></div>
    <div class="chico">Motivo: {{ $devolucion->motivo }}</div>
    <br><br>
    <div class="centro">______________________</div>
    <div class="centro chico">Firma del cliente</div>
    <div class="sep"></div>
    @if($fiscal)
        @include('tickets._fiscal-pie', ['comprobante' => $devolucion->comprobante])
    @else
        <div class="centro chico">Documento no válido como factura</div>
        @if($devolucion->comprobante_estado === 'pendiente' || ($devolucion->comprobante_estado === null && $devolucion->venta->facturar && $devolucion->venta->comprobante_estado !== 'rechazado'))
            <div class="centro chico">Nota de crédito en trámite</div>
        @endif
    @endif
</body>
</html>
