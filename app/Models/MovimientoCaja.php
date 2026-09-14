<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Efectivo que entra o sale de la caja sin ser una venta.
 */
class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    public const TIPOS = [
        'ingreso' => 'Ingreso',
        'retiro' => 'Retiro',
        'gasto' => 'Gasto',
    ];

    protected $fillable = ['uuid', 'turno_caja_id', 'tipo', 'monto', 'motivo'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movimiento): void {
            $movimiento->uuid ??= (string) Str::uuid();
        });
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }
}
