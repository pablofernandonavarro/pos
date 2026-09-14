<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleVenta extends Model
{
    protected $table = 'detalle_ventas';

    protected $fillable = [
        'venta_id',
        'product_id',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:3',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * Venta a la que pertenece el detalle.
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function devoluciones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DevolucionItem::class, 'detalle_venta_id');
    }

    /** Unidades de esta línea que todavía se pueden devolver. */
    public function cantidadDevolvible(): int
    {
        return $this->cantidad - (int) $this->devoluciones()->sum('cantidad');
    }

    /**
     * Producto vendido.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'product_id');
    }
}
