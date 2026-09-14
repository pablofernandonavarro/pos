<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Devolución o anulación de una venta, autorizada por un supervisor. El stock vuelve por
 * un MovimientoStock de tipo `devolucion` (que viaja por /sync/movimientos); esto es el
 * comprobante, que viaja por /sync/devoluciones sin tocar stock.
 */
class Devolucion extends Model
{
    protected $table = 'devoluciones';

    public const REINTEGROS = [
        'efectivo' => 'Efectivo de la caja',
        'medio_original' => 'Mismo medio de pago (tarjeta / transferencia)',
    ];

    protected $fillable = [
        'uuid', 'venta_id', 'turno_caja_id', 'numero', 'tipo', 'motivo', 'reintegro', 'total',
        'autorizado_por', 'sincronizado', 'sincronizado_at', 'comprobante_estado', 'comprobante',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
            'comprobante' => 'array',
        ];
    }

    /** Tiene nota de crédito con CAE. */
    public function notaDeCreditoAutorizada(): bool
    {
        return $this->comprobante_estado === 'autorizado' && ! empty($this->comprobante['cae']);
    }

    protected static function booted(): void
    {
        static::creating(function (self $devolucion): void {
            $devolucion->uuid ??= (string) Str::uuid();
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DevolucionItem::class);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('sincronizado', false);
    }
}
