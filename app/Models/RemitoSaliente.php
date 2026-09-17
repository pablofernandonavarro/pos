<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Copia local de un remito que esta sucursal mandó a otra. El Manager es la fuente de
 * verdad (estado, stock); esto existe para el historial, los filtros y la reimpresión sin
 * depender de la red. Ver la migración crear_remitos_salientes.
 */
class RemitoSaliente extends Model
{
    protected $table = 'remitos_salientes';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'numero',
        'destino_sucursal_id',
        'destino_nombre',
        'estado',
        'items',
        'total_unidades',
        'observaciones',
        'enviado_at',
        'confirmado_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_unidades' => 'integer',
            'enviado_at' => 'datetime',
            'confirmado_at' => 'datetime',
        ];
    }
}
