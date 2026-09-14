{{-- Encabezado de factura / nota de crédito electrónica (datos del emisor y del receptor). --}}
@php
    use App\Support\Cuit;
    $receptor = $comprobante['receptor'] ?? [];
    $identificado = ($receptor['doc_tipo'] ?? 99) !== 99 || ($receptor['condicion_iva'] ?? 'Consumidor final') !== 'Consumidor final';
@endphp
<div class="centro grande">{{ $emisor['razon_social'] ?? $comercio['nombre'] }}</div>
@if(($emisor['razon_social'] ?? null) && $comercio['nombre'] !== $emisor['razon_social'])
    <div class="centro chico">{{ $comercio['nombre'] }}</div>
@endif
<div class="centro chico">CUIT {{ $emisor['cuit'] ?? '' }} · {{ $emisor['condicion_iva_nombre'] ?? '' }}</div>
@if($emisor['ingresos_brutos'] ?? null)<div class="centro chico">Ingresos Brutos {{ $emisor['ingresos_brutos'] }}</div>@endif
@if($emisor['inicio_actividades'] ?? null)<div class="centro chico">Inicio de actividades {{ \Illuminate\Support\Carbon::parse($emisor['inicio_actividades'])->format('d/m/Y') }}</div>@endif
@if($emisor['domicilio'] ?? null)<div class="centro chico">{{ $emisor['domicilio'] }}</div>@endif
<div class="sep"></div>

<div class="centro grande">{{ mb_strtoupper($comprobante['nombre_tipo']) }}</div>
<div class="centro chico">Cód. {{ $comprobante['codigo'] }} · ORIGINAL</div>
<table>
    <tr><td>Nº</td><td class="num fuerte">{{ $comprobante['numero'] }}</td></tr>
    <tr><td>Fecha</td><td class="num">{{ \Illuminate\Support\Carbon::parse($comprobante['fecha'])->format('d/m/Y') }}</td></tr>
    @if($comprobante['asociado'] ?? null)
        <tr class="chico"><td>Asociada a</td><td class="num">{{ $comprobante['asociado']['nombre_tipo'] }} {{ $comprobante['asociado']['numero'] }}</td></tr>
    @endif
</table>
<div class="sep"></div>

@if($identificado)
    <table class="chico">
        @if($receptor['nombre'] ?? null)<tr><td colspan="2">{{ $receptor['nombre'] }}</td></tr>@endif
        <tr>
            <td>{{ $receptor['doc_tipo_nombre'] ?? 'Doc.' }}</td>
            <td class="num">{{ in_array($receptor['doc_tipo'] ?? 0, [80, 86], true) ? Cuit::formatear($receptor['doc_nro']) : $receptor['doc_nro'] }}</td>
        </tr>
        <tr><td colspan="2">{{ $receptor['condicion_iva'] ?? '' }}</td></tr>
    </table>
@else
    <div class="chico">A consumidor final</div>
@endif
<div class="sep"></div>
