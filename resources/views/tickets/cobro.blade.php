@php
    use App\Models\PagoVenta;
    use App\Support\Dinero;
    $zona = config('pos.zona_horaria');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $cobro->numero }}</title>
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
        .no-imprimir { margin: 10px 0; text-align: center; }
        @media print { .no-imprimir { display: none; } }
    </style>
</head>
<body @if($paraNavegador) onload="window.print()" @endif>
    @if($paraNavegador)
        <div class="no-imprimir"><button onclick="window.print()">Imprimir</button> <button onclick="window.close()">Cerrar</button></div>
    @endif

    <div class="centro grande">{{ $comercio['nombre'] }}</div>
    @if($comercio['cuit'])<div class="centro chico">CUIT {{ $comercio['cuit'] }}</div>@endif
    <div class="sep"></div>
    <div class="centro grande">RECIBO CUENTA CORRIENTE</div>
    <table>
        <tr><td>Recibo</td><td class="num">{{ $cobro->numero }}</td></tr>
        <tr><td>Fecha</td><td class="num">{{ $cobro->created_at->timezone($zona)->format('d/m/Y H:i') }}</td></tr>
        @if($cobro->cajero)<tr><td>Cajero</td><td class="num">{{ $cobro->cajero }}</td></tr>@endif
    </table>
    <div class="sep"></div>
    <div>Recibimos de <strong>{{ $cobro->cliente_nombre }}</strong></div>
    <table>
        <tr class="grande"><td>IMPORTE</td><td class="num">{{ Dinero::formato($cobro->importe) }}</td></tr>
        <tr><td colspan="2">{{ PagoVenta::MEDIOS[$cobro->medio] ?? $cobro->medio }}</td></tr>
        @if($saldo !== null)
            <tr><td>Saldo pendiente</td><td class="num">{{ Dinero::formato($saldo) }}</td></tr>
        @endif
    </table>
    <div class="sep"></div>
    <div class="centro chico">Documento no válido como factura</div>
</body>
</html>
