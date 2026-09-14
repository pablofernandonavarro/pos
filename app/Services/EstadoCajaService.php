<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\MovimientoStock;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto del estado de la caja para el Manager: qué tan al día está y qué tiene sin enviar.
 * Solo lee SQLite local. Las fechas van con el reloj de la caja y `generado_at` como
 * referencia, así el Manager mide antigüedades sin depender de que los relojes coincidan.
 */
class EstadoCajaService
{
    public function __construct(
        private readonly CajaService $caja,
    ) {}

    /** @return array<string, mixed> */
    public function reporte(): array
    {
        $turno = $this->caja->turnoAbierto();
        $facturasPendientes = Venta::where('comprobante_estado', 'pendiente');

        return [
            'generado_at' => now()->toIso8601String(),
            'ultima_sincronizacion_stock' => Configuracion::get('ultima_sincronizacion_stock'),
            'ultima_sincronizacion_productos' => Configuracion::get('ultima_sincronizacion_productos'),
            'catalogo_pendiente' => Configuracion::get('ultima_sincronizacion_productos') === null,
            'ventas_pendientes' => Venta::pendientes()->count(),
            'venta_pendiente_mas_vieja' => Venta::pendientes()->orderBy('fecha')->first()?->fecha?->toIso8601String(),
            'movimientos_pendientes' => MovimientoStock::pendientes()->where('tipo', '!=', 'venta')->count(),
            'devoluciones_pendientes' => Devolucion::pendientes()->count(),
            'facturas_pendientes' => (clone $facturasPendientes)->count(),
            'factura_pendiente_mas_vieja' => (clone $facturasPendientes)->orderBy('fecha')->first()?->fecha?->toIso8601String(),
            'facturas_rechazadas' => Venta::where('comprobante_estado', 'rechazado')->count(),
            'jobs_en_cola' => $this->contar('jobs'),
            'jobs_fallidos' => $this->contar('failed_jobs'),
            'turno_abierto' => $turno ? [
                'numero' => $turno->numero,
                'cajero' => $turno->cajero,
                'abierto_at' => $turno->abierto_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function contar(string $tabla): int
    {
        return Schema::hasTable($tabla) ? DB::table($tabla)->count() : 0;
    }
}
