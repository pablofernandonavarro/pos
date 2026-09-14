<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Cobro de deuda de cuenta corriente hecho en esta caja. Entra al arqueo del turno (si es
 * en efectivo) y viaja al Manager por /sync/cobros-cuenta-corriente.
 */
class CobroCuentaCorriente extends Model
{
    protected $table = 'cobros_cuenta_corriente';

    /** No se cobra deuda "a cuenta". */
    public const MEDIOS = ['efectivo', 'debito', 'credito', 'transferencia', 'qr'];

    protected $fillable = [
        'uuid', 'numero', 'cliente_id', 'cliente_nombre', 'turno_caja_id', 'medio', 'importe', 'cajero',
        'sincronizado', 'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'importe' => 'decimal:2',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cobro): void {
            $cobro->uuid ??= (string) Str::uuid();
        });
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('sincronizado', false);
    }
}
