<?php

namespace App\Services;

use App\Exceptions\CajaException;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\Venta;
use App\Support\Cuit;
use Illuminate\Database\Eloquent\Model;

/**
 * Facturación electrónica desde la caja. La caja no habla con AFIP: marca la venta para
 * facturar con los datos del cliente y el Manager pide el CAE.
 *
 * Con conexión, la factura se pide en el momento del cobro y el ticket sale con CAE y QR.
 * Sin conexión (o si AFIP no responde) la venta se registra igual con la factura pendiente;
 * el Manager la autoriza cuando le llega y la caja trae el resultado cada minuto.
 */
class FacturacionService
{
    /** Códigos de AFIP para la condición frente al IVA del receptor. */
    public const CONDICIONES_IVA = [
        5 => 'Consumidor final',
        1 => 'Responsable inscripto',
        6 => 'Monotributo',
        4 => 'Exento',
    ];

    public const DOC_TIPOS = [99 => 'Sin identificar', 96 => 'DNI', 80 => 'CUIT', 86 => 'CUIL'];

    /** Minutos que se espera a que el Manager genere la factura de una venta ya enviada. */
    private const ESPERA_COMPROBANTE_MINUTOS = 15;

    public function __construct(
        private readonly ManagerApiService $api,
        private readonly SyncService $sync,
    ) {}

    /** @return array<string, mixed> Lo último que informó el Manager (vacío si nunca). */
    public static function emisor(): array
    {
        return json_decode((string) Configuracion::get('facturacion', ''), true) ?: [];
    }

    public static function activa(): bool
    {
        return (bool) (self::emisor()['activa'] ?? false);
    }

    /** Letra que corresponde según emisor y cliente (la decide el Manager; esto es para mostrar). */
    public static function letraPara(int $condicionReceptor): ?string
    {
        if (! self::activa()) {
            return null;
        }

        if ((self::emisor()['condicion_iva'] ?? null) !== 'responsable_inscripto') {
            return 'C';
        }

        return in_array($condicionReceptor, [1, 6], true) ? 'A' : 'B';
    }

    /**
     * Valida y normaliza los datos del cliente para la factura. Se hace antes de registrar
     * la venta: una factura A sin CUIT válido la rechazaría AFIP con el cliente ya ido.
     *
     * @param  array{condicion_iva?: int|string|null, documento?: ?string, nombre?: ?string}  $cliente
     * @return array{condicion_iva: int, doc_tipo: int, doc_nro: ?string, nombre: ?string}
     */
    public static function receptor(array $cliente): array
    {
        $condicion = (int) (($cliente['condicion_iva'] ?? '') === '' ? 5 : $cliente['condicion_iva']);
        $documento = Cuit::normalizar($cliente['documento'] ?? '');
        $nombre = trim((string) ($cliente['nombre'] ?? '')) ?: null;

        if (! array_key_exists($condicion, self::CONDICIONES_IVA)) {
            throw new CajaException('Condición frente al IVA del cliente inválida.');
        }

        $docTipo = match (true) {
            $documento === '' => 99,
            strlen($documento) === 11 => 80,
            default => 96,
        };

        if ($docTipo === 80 && ! Cuit::valido($documento)) {
            throw new CajaException('El CUIT del cliente no es válido. Revisá los números.');
        }

        if ($docTipo === 96 && ! preg_match('/^\d{6,8}$/', $documento)) {
            throw new CajaException('El documento del cliente tiene que ser un DNI (7 u 8 números) o un CUIT (11 números).');
        }

        if ($condicion !== 5) {
            if ($docTipo !== 80) {
                throw new CajaException('Para facturar a un cliente '.mb_strtolower(self::CONDICIONES_IVA[$condicion]).' hace falta su CUIT.');
            }

            if ($nombre === null) {
                throw new CajaException('Falta la razón social del cliente.');
            }
        }

        return [
            'condicion_iva' => $condicion,
            'doc_tipo' => $docTipo,
            'doc_nro' => $docTipo === 99 ? null : $documento,
            'nombre' => $nombre ? mb_substr($nombre, 0, 150) : null,
        ];
    }

    /**
     * Pide la factura en el momento. Devuelve null si quedó autorizada; si no, un aviso para
     * el cajero (la venta ya está registrada: nada de esto la deshace).
     */
    public function facturarAhora(Venta $venta): ?string
    {
        if (! $venta->facturar || $venta->facturaAutorizada()) {
            return null;
        }

        if (! $this->sync->puedeEnviarVentas()) {
            return 'Factura pendiente: la caja todavía está bajando el catálogo. Se emite sola en unos minutos.';
        }

        $respuesta = $this->api->facturarVenta($this->sync->payloadVenta($venta));

        if (! $respuesta['success']) {
            return "Factura pendiente ({$respuesta['error']}). Se emite sola cuando vuelva la conexión; reimprimila desde Ventas.";
        }

        if (($respuesta['venta']['uuid'] ?? null) === $venta->uuid) {
            $this->sync->confirmarVentaEnviada($venta);
        }

        if (empty($respuesta['comprobante'])) {
            $venta->update(['facturar' => false, 'comprobante_estado' => null]);

            return 'El Manager no tiene la facturación activa para esta caja: la venta quedó sin factura.';
        }

        $this->aplicar($venta, $respuesta['comprobante']);

        return match ($venta->comprobante_estado) {
            'autorizado' => null,
            'rechazado' => 'AFIP rechazó la factura: '.($venta->comprobante['error'] ?? 'sin detalle').'. Revisalo en el Manager.',
            default => 'Factura pendiente de CAE ('.($venta->comprobante['error'] ?? 'AFIP no respondió').'). Reimprimila desde Ventas cuando se autorice.',
        };
    }

    /**
     * Trae el resultado de las facturas y notas de crédito que siguen pendientes.
     *
     * @return array{success: bool, actualizados?: int, error?: string}
     */
    public function actualizarPendientes(): array
    {
        $ventas = Venta::where('comprobante_estado', 'pendiente')
            ->where('sincronizado', true)
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'uuid', 'sincronizado_at', 'comprobante_estado']);

        // Una devolución lleva nota de crédito si su venta tiene factura (pendiente o con CAE).
        $devoluciones = Devolucion::where('sincronizado', true)
            ->where(fn ($q) => $q->whereNull('comprobante_estado')->orWhere('comprobante_estado', 'pendiente'))
            ->whereHas('venta', fn ($q) => $q->whereIn('comprobante_estado', ['pendiente', 'autorizado']))
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'uuid', 'venta_id', 'comprobante_estado']);

        if ($ventas->isEmpty() && $devoluciones->isEmpty()) {
            return ['success' => true, 'actualizados' => 0];
        }

        $respuesta = $this->api->estadoComprobantes($ventas->pluck('uuid')->all(), $devoluciones->pluck('uuid')->all());

        if (! $respuesta['success']) {
            return $respuesta;
        }

        $actualizados = 0;

        foreach ($ventas as $venta) {
            $comprobante = $respuesta['ventas'][$venta->uuid] ?? null;

            if ($comprobante) {
                $this->aplicar(Venta::find($venta->id), $comprobante);
                $actualizados++;
            } elseif ($venta->sincronizado_at?->lt(now()->subMinutes(self::ESPERA_COMPROBANTE_MINUTOS))) {
                // El Manager recibió la venta y no generó factura: facturación desactivada o
                // la sucursal sin punto de venta. Que no quede "pendiente" para siempre.
                Venta::whereKey($venta->id)->update([
                    'comprobante_estado' => 'rechazado',
                    'comprobante' => json_encode(['estado' => 'rechazado', 'error' => 'El Manager no generó la factura (facturación desactivada o sucursal sin punto de venta).']),
                ]);
                $actualizados++;
            }
        }

        foreach ($devoluciones as $devolucion) {
            if ($comprobante = $respuesta['devoluciones'][$devolucion->uuid] ?? null) {
                $this->aplicar(Devolucion::find($devolucion->id), $comprobante);
                $actualizados++;
            }
        }

        return ['success' => true, 'actualizados' => $actualizados];
    }

    /** @param  array<string, mixed>  $comprobante */
    private function aplicar(Model $modelo, array $comprobante): void
    {
        $modelo->update([
            'comprobante_estado' => $comprobante['estado'] ?? 'pendiente',
            'comprobante' => $comprobante,
        ]);
    }
}
