@php
    $zona = config('pos.zona_horaria');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Remito {{ $remito->numero }}</title>
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
        .no-imprimir { margin: 10px 0; text-align: center; }
        @media print { .no-imprimir { display: none; } }
    </style>
</head>
<body @if($paraNavegador) onload="window.print()" @endif>
    @if($paraNavegador)
        <div class="no-imprimir"><button onclick="window.print()">Imprimir</button> <button onclick="window.close()">Cerrar</button></div>
    @endif

    <div class="centro grande">{{ $comercio['nombre'] }}</div>
    <div class="sep"></div>
    <div class="centro grande">REMITO</div>
    <table>
        <tr><td>Número</td><td class="num">{{ $remito->numero }}</td></tr>
        <tr><td>Destino</td><td class="num">{{ $remito->destino_nombre }}</td></tr>
        <tr><td>Fecha</td><td class="num">{{ $remito->enviado_at->timezone($zona)->format('d/m/Y H:i') }}</td></tr>
    </table>
    <div class="sep"></div>
    <table>
        @foreach($remito->items as $item)
            <tr><td colspan="2">{{ $item['nombre'] }}</td></tr>
            <tr class="chico"><td>{{ $item['codigo'] ?? '-' }}</td><td class="num">{{ $item['cantidad'] }} u.</td></tr>
        @endforeach
    </table>
    <div class="sep"></div>
    <table>
        <tr class="grande"><td>TOTAL</td><td class="num">{{ number_format($remito->total_unidades) }} u.</td></tr>
    </table>
    @if($remito->observaciones)
        <div class="sep"></div>
        <div class="chico">Obs: {{ $remito->observaciones }}</div>
    @endif
    <div class="sep"></div>
    <br>
    <div class="centro">______________________</div>
    <div class="centro chico">Firma de quien recibe</div>
    <div class="sep"></div>
    <div class="centro chico">Documento no válido como factura</div>
</body>
</html>
