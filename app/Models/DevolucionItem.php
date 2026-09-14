<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevolucionItem extends Model
{
    protected $table = 'devolucion_items';

    protected $fillable = ['devolucion_id', 'detalle_venta_id', 'product_id', 'cantidad', 'importe'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'importe' => 'decimal:2',
        ];
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class);
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(DetalleVenta::class, 'detalle_venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'product_id');
    }
}
