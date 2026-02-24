<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoStock extends Model
{
    protected $table = 'movimientos_stock';

    protected $fillable = [
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
