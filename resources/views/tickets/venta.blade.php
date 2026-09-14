@php
    use App\Models\PagoVenta;
    use App\Support\Dinero;
    $zona = config('pos.zona_horaria');
    $fiscal = $venta->facturaAutorizada();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $fiscal ? $venta->comprobante['nombre_tipo'].' '.$venta->comprobante['numero'] : 'Ticket '.$venta->numero_venta }}</title>
    {{-- CSS propio y sin recursos externos: se imprime en térmica de 80 mm y, en la app de
         escritorio, se carga como data: URL sin acceso a los assets de la app. --}}
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
        .fuerte { font-weight: bold; }
        .chico { font-size: 11px; }
        .qr svg { width: 32mm; height: 32mm; margin: 4px auto; display: block; }
        .no-imprimir { margin: 10px 0; text-align: center; }
        @media print { .no-imprimir { display: none; } }
    </style>
</head>
<body @if($paraNavegador) onload="window.print()" @endif>
    @if($paraNavegador)
        <div class="no-imprimir">
            <button onclick="window.print()">Imprimir</button>
            <button onclick="window.close()">Cerrar</button>
        </div>
    @endif

    @if($fiscal)
        @include('tickets._fiscal-encabezado', ['comprobante' => $venta->comprobante])
    @else
        <div class="centro grande">{{ $comercio['nombre'] }}</div>
        @if($comercio['cuit'])<div class="centro chico">CUIT {{ $comercio['cuit'] }}</div>@endif
        @if($comercio['direccion'])<div class="centro chico">{{ $comercio['direccion'] }}</div>@endif
        <div class="sep"></div>
    @endif

    <table>
        <tr><td>Venta</td><td class="num">{{ $venta->numero_venta }}</td></tr>
        <tr><td>Fecha</td><td class="num">{{ $venta->fecha->timezone($zona)->format('d/m/Y H:i') }}</td></tr>
        @if($venta->cajero)<tr><td>Cajero</td><td class="num">{{ $venta->cajero }}</td></tr>@endif
        @if(! $fiscal && ($venta->cliente_nombre || $venta->cliente_documento))
            <tr><td>Cliente</td><td class="num">{{ trim($venta->cliente_nombre.' '.$venta->cliente_documento) }}</td></tr>
        @endif
    </table>
    <div class="sep"></div>

    <table>
        @foreach($venta->detalles as $detalle)
            <tr><td colspan="2">{{ $detalle->producto?->nombre ?? 'Artículo '.$detalle->product_id }}</td></tr>
            <tr class="chico">
                <td>{{ $detalle->cantidad }} x {{ Dinero::formato($detalle->precio_unitario) }}</td>
                <td class="num">{{ Dinero::formato($detalle->subtotal) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="sep"></div>

    <table>
        @if((float) $venta->descuento > 0)
            <tr><td>Subtotal</td><td class="num">{{ Dinero::formato($venta->subtotal) }}</td></tr>
            @if((float) $venta->descuento_manual > 0)
                <tr class="chico"><td>Descuento</td><td class="num">-{{ Dinero::formato($venta->descuento_manual) }}</td></tr>
            @endif
            @foreach($venta->pagos->where('descuento', '>', 0) as $pago)
                <tr class="chico"><td>{{ $pago->promocion_nombre ?? 'Descuento' }}</td><td class="num">-{{ Dinero::formato($pago->descuento) }}</td></tr>
            @endforeach
        @endif
        <tr class="grande"><td>TOTAL</td><td class="num">{{ Dinero::formato($venta->total) }}</td></tr>
    </table>
    <div class="sep"></div>

    <table>
        @foreach($venta->pagos as $pago)
            <tr>
                <td>
                    {{ PagoVenta::MEDIOS[$pago->medio] ?? $pago->medio }}
                    @if($pago->tarjeta) {{ PagoVenta::TARJETAS[$pago->tarjeta] ?? $pago->tarjeta }} @endif
                    @if(($pago->cuotas ?? 1) > 1) {{ $pago->cuotas }} cuotas @endif
                </td>
                <td class="num">{{ Dinero::formato($pago->importe) }}</td>
            </tr>
            @if($pago->recibido !== null)
                <tr class="chico"><td>Recibido</td><td class="num">{{ Dinero::formato($pago->recibido) }}</td></tr>
                <tr class="chico fuerte"><td>Vuelto</td><td class="num">{{ Dinero::formato($pago->vuelto) }}</td></tr>
            @endif
        @endforeach
    </table>

    <div class="sep"></div>
    @if($fiscal)
        @include('tickets._fiscal-pie', ['comprobante' => $venta->comprobante])
    @else
        @if($comercio['pie'])<div class="centro chico">{{ $comercio['pie'] }}</div>@endif
        <div class="centro chico">Documento no válido como factura</div>
        @if($venta->facturar && $venta->comprobante_estado === 'pendiente')
            <div class="centro chico">Factura en trámite: se entrega al autorizarse</div>
        @endif
    @endif
    @if($fiscal && $comercio['pie'])<div class="centro chico">{{ $comercio['pie'] }}</div>@endif
    <div class="centro chico">¡Gracias por su compra!</div>
</body>
</html>
