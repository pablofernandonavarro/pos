<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Remito en camino a esta sucursal, tal como lo informó el Manager en la última
 * sincronización. Ver la migración create_remitos_entrantes_table.
 */
class RemitoEntrante extends Model
{
    protected $table = 'remitos_entrantes';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'numero',
        'origen',
        'remitido_at',
        'observaciones',
        'items',
        'total_unidades',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'remitido_at' => 'datetime',
            'total_unidades' => 'integer',
        ];
    }
}
