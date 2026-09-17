<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Copia local de un remito que esta caja mandó a otra sucursal. El Manager es la fuente
 * de verdad; esto existe para el historial y la reimpresión sin depender de la red.
 * Ver la migración crear_remitos_salientes.
 */
class RemitoSaliente extends Model
{
    protected $table = 'remitos_salientes';

    protected $fillable = [
        'numero',
        'destino_sucursal_id',
        'destino_nombre',
        'items',
        'total_unidades',
        'observaciones',
        'enviado_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_unidades' => 'integer',
            'enviado_at' => 'datetime',
        ];
    }
}
