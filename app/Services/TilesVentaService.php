<?php

namespace App\Services;

use App\Models\DetalleVenta;
use App\Models\Producto;
use Illuminate\Support\Collection;

/**
 * Accesos rápidos de la pantalla de venta (categorías con más productos y los artículos
 * más vendidos), para no depender solo del buscador de texto en el mostrador. Se generan
 * solos a partir del catálogo y el historial de ventas: no hay pantalla de configuración
 * todavía, así que no hay nada que un vendedor pueda romper o dejar desactualizado.
 */
class TilesVentaService
{
    private const DIAS_MAS_VENDIDOS = 30;

    /**
     * Grupos con más artículos vendibles, para ofrecer primero las categorías grandes.
     *
     * @return Collection<int, object{grupo: string, total: int}>
     */
    public function categorias(int $limite = 6): Collection
    {
        return Producto::vendible()
            ->whereNotNull('n_grupo')
            ->where('n_grupo', '!=', '')
            ->selectRaw('n_grupo as grupo, count(*) as total')
            ->groupBy('n_grupo')
            ->orderByDesc('total')
            ->limit($limite)
            ->get();
    }

    /**
     * Lo más vendido en los últimos 30 días, para venta de un toque sin pasar por ninguna
     * categoría. Se ordena por unidades vendidas, no por cuántas veces se llame acá.
     *
     * @return array<int, array{id: int, nombre: string, codigo: ?string, precio: float, stock: int, imagen: ?string}>
     */
    public function masVendidos(int $limite = 6, ?int $listaId = null): array
    {
        $ids = DetalleVenta::whereHas('venta', fn ($q) => $q->where('fecha', '>=', now()->subDays(self::DIAS_MAS_VENDIDOS)))
            ->selectRaw('product_id, sum(cantidad) as total')
            ->groupBy('product_id')
            ->orderByDesc('total')
            ->limit($limite)
            ->pluck('product_id');

        $productos = Producto::vendible()->whereIn('id', $ids)->get()->keyBy('id');

        // En el orden de más vendido a menos, no el que devuelva el whereIn.
        return $ids->map(fn ($id) => $productos->get($id))
            ->filter()
            ->map(fn (Producto $p) => $this->comoArray($p, $listaId))
            ->values()
            ->all();
    }

    /**
     * Todo lo vendible de un grupo puntual, para cuando se toca su tile.
     *
     * @return array<int, array{id: int, nombre: string, codigo: ?string, precio: float, stock: int, imagen: ?string}>
     */
    public function productosDeCategoria(string $grupo, ?int $listaId = null, int $limite = 100): array
    {
        return Producto::vendible()
            ->where('n_grupo', $grupo)
            ->orderBy('nombre')
            ->limit($limite)
            ->get()
            ->map(fn (Producto $p) => $this->comoArray($p, $listaId))
            ->all();
    }

    /**
     * @return array{id: int, nombre: string, codigo: ?string, precio: float, stock: int, imagen: ?string}
     */
    private function comoArray(Producto $p, ?int $listaId): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->modelo_nombre ?? $p->nombre,
            'codigo' => $p->codigo_interno ?? $p->codigo_barras,
            'precio' => $p->getPrecioEfectivo($listaId),
            'stock' => $p->stock,
            'imagen' => $p->imagen_url,
        ];
    }
}
