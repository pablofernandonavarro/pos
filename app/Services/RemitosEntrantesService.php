<?php

namespace App\Services;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\RemitoEntrante;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remitos que vienen en camino a esta sucursal: la copia local (para la alerta y la
 * pantalla de recepción) y la recepción contra el Manager.
 */
class RemitosEntrantesService
{
    public function __construct(
        private readonly ManagerApiService $managerApi
    ) {}

    /**
     * Reemplaza la copia local por lo que informa el Manager. Lo que ya no viene (lo
     * recibió otra caja, lo recibieron o cancelaron desde el Manager) desaparece.
     *
     * @return array{success: bool, cantidad?: int, nuevos?: array<int, RemitoEntrante>, error?: string}
     */
    public function sincronizar(): array
    {
        $respuesta = $this->managerApi->obtenerRemitos();

        if (! $respuesta['success']) {
            return $respuesta;
        }

        $remitos = collect($respuesta['data']);
        $conocidos = RemitoEntrante::pluck('id')->all();

        DB::transaction(function () use ($remitos) {
            RemitoEntrante::whereNotIn('id', $remitos->pluck('id'))->delete();

            foreach ($remitos as $r) {
                RemitoEntrante::updateOrCreate(['id' => $r['id']], [
                    'numero' => $r['numero'],
                    'origen' => $r['origen'],
                    // A UTC antes de guardar: el cast datetime descarta el offset del ISO.
                    'remitido_at' => isset($r['remitido_at']) ? Carbon::parse($r['remitido_at'])->utc() : null,
                    'observaciones' => $r['observaciones'] ?? null,
                    'items' => $r['items'],
                    'total_unidades' => collect($r['items'])->sum('cantidad'),
                ]);
            }
        });

        return [
            'success' => true,
            'cantidad' => $remitos->count(),
            'nuevos' => RemitoEntrante::whereIn('id', $remitos->pluck('id')->diff($conocidos))->get()->all(),
        ];
    }

    /**
     * Requiere conexión: el stock de la sucursal vive en el Manager, y si la caja lo
     * sumara sola, el pull de stock de cada minuto se lo pisaría hasta que llegue al
     * Manager. Es la misma razón por la que no se crea un movimiento local: se enviaría
     * por /sync/movimientos y el Manager sumaría la mercadería dos veces.
     *
     * @return array{success: bool, mensaje?: string, error?: string}
     */
    public function recibir(int $remitoId, ?array $cantidadesRecibidas = null, ?int $destinoRechazadosId = null): array
    {
        $remito = RemitoEntrante::find($remitoId);
        // Antes de llamar, por la misma razón que en SyncService::syncStock().
        $pendientes = MovimientoStock::pendientesPorProducto();
        $respuesta = $this->managerApi->recibirRemito($remitoId, $cantidadesRecibidas, $destinoRechazadosId);

        if (! $respuesta['success']) {
            // Cancelado o ya no es para esta sucursal: no tiene sentido seguir mostrándolo.
            if (in_array($respuesta['codigo'] ?? null, [404, 409], true)) {
                $remito?->delete();
            }

            return ['success' => false, 'error' => $respuesta['error']];
        }

        DB::transaction(function () use ($respuesta, $remito, $pendientes) {
            // El stock que devuelve el Manager es el de la sucursal ya con lo recibido: se
            // aplica sin esperar al pull del minuto siguiente, más lo que la caja vendió y
            // todavía no llegó al Manager.
            foreach ($respuesta['stock'] as $fila) {
                Producto::whereKey($fila['product_id'])->update([
                    'stock' => (int) $fila['cantidad'] + ($pendientes[$fila['product_id']] ?? 0),
                ]);
            }

            $remito?->delete();
        });

        $numero = $remito?->numero ?? $remitoId;
        $hijo = $respuesta['remito_hijo'] ?? null;

        $mensaje = match (true) {
            $respuesta['status'] === 'ya_recibido' => "El remito #{$numero} ya estaba recibido. Stock actualizado.",
            $remito !== null => "Remito #{$numero} recibido: se sumaron {$remito->total_unidades} unidades al stock.",
            default => "Remito #{$numero} recibido. Stock actualizado.",
        };

        if ($hijo) {
            $mensaje .= " Remito hijo #{$hijo['numero']} hacia {$hijo['destino']} por rechazados.";
        }

        return [
            'success' => true,
            'mensaje' => $mensaje,
        ];
    }
}
