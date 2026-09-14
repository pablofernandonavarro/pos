<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MovimientoStock extends Model
{
    protected $table = 'movimientos_stock';

    protected $fillable = [
        'uuid',
        'product_id',
        'tipo',
        'cantidad',
        'referencia',
        'fecha',
        'sincronizado',
        'sincronizado_at',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha' => 'datetime',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Clave de idempotencia para el push al Manager. Ver Venta::booted().
     */
    protected static function booted(): void
    {
        static::creating(function (self $movimiento): void {
            $movimiento->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Lo que la caja movió y el Manager todavía no sabe, por producto.
     *
     * El stock que devuelve el Manager no incluye estas unidades. Si se aplicara tal cual,
     * una venta hecha offline (o en los segundos antes de enviarse) "volvería" al stock
     * y se podría vender dos veces. Incluye los movimientos de venta: viajan dentro del
     * push de la venta y se marcan sincronizados recién cuando el Manager la confirma.
     *
     * @param  array<int, int>|null  $productIds
     * @return array<int, int> product_id => cantidad (negativa si salió mercadería)
     */
    public static function pendientesPorProducto(?array $productIds = null): array
    {
        return self::pendientes()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(cantidad) as total')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Producto del movimiento.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'product_id');
    }

    /**
     * Scope para movimientos pendientes de sincronizar.
     */
    public function scopePendientes($query)
    {
        return $query->where('sincronizado', false);
    }

    /**
     * Scope para movimientos sincronizados.
     */
    public function scopeSincronizados($query)
    {
        return $query->where('sincronizado', true);
    }

    /**
     * Marca el movimiento como sincronizado.
     */
    public function marcarSincronizado(): void
    {
        $this->update([
            'sincronizado' => true,
            'sincronizado_at' => now(),
        ]);
    }

    /**
     * Registra un movimiento de stock y actualiza el stock del producto.
     */
    public static function registrar(int $productId, string $tipo, int $cantidad, ?string $referencia = null, ?string $observaciones = null): self
    {
        $producto = Producto::findOrFail($productId);

        $movimiento = self::create([
            'product_id' => $productId,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'referencia' => $referencia,
            'fecha' => now(),
            'observaciones' => $observaciones,
        ]);

        // Actualizar stock del producto
        if ($cantidad > 0) {
            $producto->aumentarStock(abs($cantidad));
        } else {
            $producto->reducirStock(abs($cantidad));
        }

        return $movimiento;
    }
}
