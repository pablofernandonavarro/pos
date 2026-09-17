<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\RemitoSaliente;
use App\Models\Sucursal;

class RemitosSalientesService
{
    public function __construct(private ManagerApiService $api) {}

    public function configuracion(): array
    {
        return $this->api->obtenerConfiguracionRemitos();
    }

    public function sucursalesDestino(): array
    {
        $result = $this->api->obtenerSucursales();

        if (! $result['success']) {
            return [];
        }

        return $result['data'] ?? [];
    }

    /**
     * Crea el remito en el Manager (fuente de verdad del stock) y, si sale bien, guarda una
     * copia local para el historial y la reimpresión sin depender de la red. Si el Manager
     * lo aceptó pero la copia local falla, el remito ya existe igual: no vale la pena
     * revertir nada por eso.
     *
     * @param  array<int, int>  $items  product_id => cantidad
     */
    public function crear(int $destinoSucursalId, array $items, ?string $observaciones = null): array
    {
        $resultado = $this->api->crearRemito($destinoSucursalId, $items, $observaciones);

        if ($resultado['success']) {
            $this->guardarCopiaLocal($destinoSucursalId, $items, $observaciones, $resultado['data'] ?? []);
        }

        return $resultado;
    }

    private function guardarCopiaLocal(int $destinoSucursalId, array $items, ?string $observaciones, array $data): void
    {
        $productos = Producto::whereIn('id', array_keys($items))->get()->keyBy('id');

        $detalle = collect($items)->map(fn (int $cantidad, int $productoId) => [
            'product_id' => $productoId,
            'nombre' => $productos->get($productoId)?->nombre ?? "Producto {$productoId}",
            'codigo' => $productos->get($productoId)?->codigo_interno,
            'cantidad' => $cantidad,
        ])->values()->all();

        RemitoSaliente::create([
            'numero' => $data['numero'] ?? '-',
            'destino_sucursal_id' => $destinoSucursalId,
            'destino_nombre' => Sucursal::find($destinoSucursalId)?->nombre ?? 'Sucursal',
            'items' => $detalle,
            'total_unidades' => array_sum($items),
            'observaciones' => $observaciones,
            'enviado_at' => now(),
        ]);
    }
}
