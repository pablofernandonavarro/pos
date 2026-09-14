{{-- Pie de factura / nota de crédito electrónica: IVA, CAE y QR. --}}
@php
    use App\Support\Dinero;
    // El SVG del QR lo genera el Manager; se imprime crudo solo si es un <svg> sin scripts ni
    // atributos de evento (la página se carga en Electron).
    $svg = (string) ($comprobante['qr_svg'] ?? '');
    $svgSeguro = preg_match('/^\s*<svg[\s>]/i', $svg) && ! preg_match('/<script|\son\w+\s*=|javascript:|<foreignObject/i', $svg);
@endphp
@if($comprobante['letra'] === 'A')
    <table class="chico">
        @foreach($comprobante['alicuotas'] ?? [] as $alicuota)
            <tr><td>Neto gravado {{ rtrim(rtrim(number_format($alicuota['porcentaje'], 2, ',', ''), '0'), ',') }}%</td><td class="num">{{ Dinero::formato($alicuota['base']) }}</td></tr>
            <tr><td>IVA {{ rtrim(rtrim(number_format($alicuota['porcentaje'], 2, ',', ''), '0'), ',') }}%</td><td class="num">{{ Dinero::formato($alicuota['importe']) }}</td></tr>
        @endforeach
    </table>
    <div class="sep"></div>
@elseif($comprobante['letra'] === 'B')
    <div class="chico">Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)</div>
    <table class="chico">
        <tr><td>IVA contenido</td><td class="num">{{ Dinero::formato($comprobante['importe_iva']) }}</td></tr>
    </table>
    <div class="sep"></div>
@endif

<table>
    <tr><td>CAE Nº</td><td class="num fuerte">{{ $comprobante['cae'] }}</td></tr>
    <tr class="chico"><td>Vto. CAE</td><td class="num">{{ \Illuminate\Support\Carbon::parse($comprobante['cae_vencimiento'])->format('d/m/Y') }}</td></tr>
</table>
@if($svgSeguro)
    <div class="centro qr">{!! $svg !!}</div>
@endif
<div class="centro chico">Comprobante autorizado</div>
