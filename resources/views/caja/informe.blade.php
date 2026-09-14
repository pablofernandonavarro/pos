@php
    use App\Models\MovimientoCaja;
    use App\Models\PagoVenta;
    use App\Support\Dinero;
    use Illuminate\Support\Carbon;
    $zona = config('pos.zona_horaria');
    $fecha = fn (?string $iso) => $iso ? Carbon::parse($iso)->timezone($zona)->format('d/m/Y H:i') : '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe {{ $tipo }} #{{ $turno->numero }}</title>
    {{-- Estilos propios y sin Vite: se imprime en térmica de 80 mm (Epson TM-T20) y tiene
         que verse igual sin depender del CSS de la app. --}}
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; color: #000; margin: 0 auto; width: 74mm; }
        h1 { font-size: 16px; text-align: center; margin: 4px 0; }
        .centro { text-align: center; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .fuerte { font-weight: bold; }
        .titulo { font-weight: bold; margin-top: 6px; }
        .no-imprimir { margin: 12px 0; text-align: center; }
        @media print { .no-imprimir { display: none; } }
    </style>
</head>
@php $paraNavegador ??= true; @endphp
<body @if($paraNavegador) onload="window.print()" @endif>
    @if($paraNavegador)
        <div class="no-imprimir">
            <button onclick="window.print()">Imprimir</button>
            <button onclick="window.close()">Cerrar</button>
        </div>
    @endif

    <h1>INFORME {{ $tipo }} #{{ $turno->numero }}</h1>
    <div class="centro">{{ $pdv }} · {{ $sucursal }}</div>
    <div class="centro">{{ $tipo === 'X' ? 'Parcial, sin cierre' : 'Cierre de caja' }}</div>
    <div class="sep"></div>

    <table>
        <tr><td>Cajero</td><td class="num">{{ $resumen['turno']['cajero'] }}</td></tr>
        <tr><td>Apertura</td><td class="num">{{ $fecha($resumen['turno']['abierto_at']) }}</td></tr>
        <tr><td>{{ $tipo === 'X' ? 'Emitido' : 'Cierre' }}</td><td class="num">{{ $tipo === 'X' ? now()->timezone($zona)->format('d/m/Y H:i') : $fecha($resumen['turno']['cerrado_at']) }}</td></tr>
        @if(!empty($resumen['numeracion']['desde']))
            <tr><td>Ventas</td><td class="num">{{ $resumen['numeracion']['desde'] }} a {{ $resumen['numeracion']['hasta'] }}</td></tr>
        @endif
    </table>

    <div class="sep"></div>
    <div class="titulo">VENTAS</div>
    <table>
        <tr><td>Cantidad</td><td class="num">{{ $resumen['ventas']['cantidad'] }}</td></tr>
        <tr><td>Unidades</td><td class="num">{{ $resumen['ventas']['unidades'] }}</td></tr>
        <tr><td>Subtotal</td><td class="num">{{ Dinero::formato($resumen['ventas']['subtotal']) }}</td></tr>
        <tr><td>Descuentos</td><td class="num">-{{ Dinero::formato($resumen['ventas']['descuentos']) }}</td></tr>
        @if(($resumen['ventas']['descuentos_manuales'] ?? 0) > 0)
            <tr><td>&nbsp;incl. manuales</td><td class="num">-{{ Dinero::formato($resumen['ventas']['descuentos_manuales']) }}</td></tr>
        @endif
        <tr class="fuerte"><td>TOTAL</td><td class="num">{{ Dinero::formato($resumen['ventas']['total']) }}</td></tr>
        @if(($resumen['devoluciones']['cantidad'] ?? 0) > 0)
            <tr><td>Devoluciones ({{ $resumen['devoluciones']['cantidad'] }})</td><td class="num">-{{ Dinero::formato($resumen['devoluciones']['total']) }}</td></tr>
            <tr class="fuerte"><td>NETO</td><td class="num">{{ Dinero::formato($resumen['ventas']['neto']) }}</td></tr>
        @endif
        <tr><td>Ticket promedio</td><td class="num">{{ Dinero::formato($resumen['ventas']['ticket_promedio']) }}</td></tr>
    </table>

    <div class="sep"></div>
    <div class="titulo">POR MEDIO DE PAGO</div>
    <table>
        @forelse($resumen['por_medio'] as $medio => $importe)
            <tr><td>{{ PagoVenta::MEDIOS[$medio] ?? $medio }}</td><td class="num">{{ Dinero::formato($importe) }}</td></tr>
        @empty
            <tr><td>Sin ventas</td></tr>
        @endforelse
    </table>

    @if(!empty($resumen['tarjetas']))
        <div class="titulo">TARJETAS</div>
        <table>
            @foreach($resumen['tarjetas'] as $t)
                <tr>
                    <td>{{ PagoVenta::MEDIOS[$t['medio']] }} {{ PagoVenta::TARJETAS[$t['tarjeta']] ?? '' }}@if(($t['cuotas'] ?? 1) > 1) {{ $t['cuotas'] }}c @endif x{{ $t['cantidad'] }}</td>
                    <td class="num">{{ Dinero::formato($t['importe']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(($resumen['cuenta_corriente']['ventas'] ?? 0) > 0 || ($resumen['cuenta_corriente']['cobros'] ?? 0) > 0)
        <div class="titulo">CUENTA CORRIENTE</div>
        <table>
            <tr><td>Vendido a cuenta</td><td class="num">{{ Dinero::formato($resumen['cuenta_corriente']['ventas']) }}</td></tr>
            @foreach($resumen['cuenta_corriente']['cobros_por_medio'] as $medio => $importe)
                <tr><td>Cobrado · {{ PagoVenta::MEDIOS[$medio] ?? $medio }}</td><td class="num">{{ Dinero::formato($importe) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if(!empty($resumen['promociones']))
        <div class="titulo">PROMOCIONES</div>
        <table>
            @foreach($resumen['promociones'] as $p)
                <tr><td>{{ $p['nombre'] }} x{{ $p['cantidad'] }}</td><td class="num">-{{ Dinero::formato($p['descuento']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <div class="sep"></div>
    <div class="titulo">EFECTIVO</div>
    <table>
        <tr><td>Fondo inicial</td><td class="num">{{ Dinero::formato($resumen['efectivo']['fondo_inicial']) }}</td></tr>
        <tr><td>Ventas</td><td class="num">{{ Dinero::formato($resumen['efectivo']['ventas']) }}</td></tr>
        @if(($resumen['efectivo']['cobros_cuenta_corriente'] ?? 0) > 0)
            <tr><td>Cobros cta. cte.</td><td class="num">{{ Dinero::formato($resumen['efectivo']['cobros_cuenta_corriente']) }}</td></tr>
        @endif
        <tr><td>Ingresos</td><td class="num">{{ Dinero::formato($resumen['efectivo']['ingresos']) }}</td></tr>
        <tr><td>Retiros</td><td class="num">-{{ Dinero::formato($resumen['efectivo']['retiros']) }}</td></tr>
        <tr><td>Gastos</td><td class="num">-{{ Dinero::formato($resumen['efectivo']['gastos']) }}</td></tr>
        @if(($resumen['efectivo']['devoluciones'] ?? 0) > 0)
            <tr><td>Devoluciones</td><td class="num">-{{ Dinero::formato($resumen['efectivo']['devoluciones']) }}</td></tr>
        @endif
        <tr class="fuerte"><td>Esperado</td><td class="num">{{ Dinero::formato($resumen['efectivo']['esperado']) }}</td></tr>
        @if(array_key_exists('contado', $resumen['efectivo']))
            <tr><td>Contado</td><td class="num">{{ Dinero::formato($resumen['efectivo']['contado']) }}</td></tr>
            <tr class="fuerte"><td>{{ $resumen['efectivo']['diferencia'] < 0 ? 'FALTANTE' : ($resumen['efectivo']['diferencia'] > 0 ? 'SOBRANTE' : 'DIFERENCIA') }}</td><td class="num">{{ Dinero::formato(abs($resumen['efectivo']['diferencia'])) }}</td></tr>
        @endif
    </table>

    @if(!empty($resumen['devoluciones']['detalle']))
        <div class="titulo">DEVOLUCIONES</div>
        <table>
            @foreach($resumen['devoluciones']['detalle'] as $d)
                <tr><td>{{ $d['numero'] }} ({{ $d['venta'] }}) {{ $d['reintegro'] === 'efectivo' ? 'EF' : 'MO' }} · {{ $d['autorizado_por'] }}</td><td class="num">-{{ Dinero::formato($d['total']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if(!empty($resumen['movimientos']))
        <div class="titulo">MOVIMIENTOS</div>
        <table>
            @foreach($resumen['movimientos'] as $m)
                <tr><td>{{ MovimientoCaja::TIPOS[$m['tipo']] ?? $m['tipo'] }}: {{ $m['motivo'] }}</td><td class="num">{{ $m['tipo'] === 'ingreso' ? '' : '-' }}{{ Dinero::formato($m['monto']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if($turno->observaciones)
        <div class="sep"></div>
        <div>Obs: {{ $turno->observaciones }}</div>
    @endif

    @if($tipo === 'Z')
        <div class="sep"></div>
        <br><br>
        <div class="centro">______________________</div>
        <div class="centro">Firma del cajero</div>
    @endif
    <div class="sep"></div>
    <div class="centro">Documento no válido como factura</div>
</body>
</html>
