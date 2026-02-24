<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Precio extends Model
{
    protected $table = 'precios';

    protected $fillable = [
        'lista_precio_id',
        'product_id',
        'precio_override',
        'vigencia_desde',
        'vigencia_hasta',
        'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'precio_override' => 'decimal:3',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
            'sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Lista de precios a la que pertenece.
     */
    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    /**
     * Producto al que se aplica el precio.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'product_id');
    }

    /**
     * Verifica si el precio está vigente.
     */
    public function isVigente(): bool
    {
        $hoy = now()->toDateString();

        $desdeCumple = is_null($this->vigencia_desde) || $this->vigencia_desde <= $hoy;
        $hastaCumple = is_null($this->vigencia_hasta) || $this->vigencia_hasta >= $hoy;

        return $desdeCumple && $hastaCumple;
    }
}
