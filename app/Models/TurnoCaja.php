<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Turno de caja: de la apertura con fondo inicial al cierre Z. Toda venta pertenece a un
 * turno; sin turno abierto no se vende.
 */
class TurnoCaja extends Model
{
    protected $table = 'turnos_caja';

    protected $fillable = [
        'uuid',
        'numero',
        'cajero',
        'cajero_id',
        'fondo_inicial',
        'abierto_at',
        'cerrado_at',
        'efectivo_contado',
        'resumen',
        'observaciones',
        'sincronizado',
        'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fondo_inicial' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'abierto_at' => 'datetime',
            'cerrado_at' => 'datetime',
            'resumen' => 'array',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $turno): void {
            $turno->uuid ??= (string) Str::uuid();
        });
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class);
    }

    public function scopeAbierto(Builder $query): Builder
    {
        return $query->whereNull('cerrado_at');
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('sincronizado', false);
    }

    public function estaAbierto(): bool
    {
        return $this->cerrado_at === null;
    }
}
