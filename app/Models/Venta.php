<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'lista_precio_id',
        'numero_venta',
        'fecha',
        'subtotal',
        'descuento',
        'total',
        'sincronizado',
        'sincronizado_at',
        'cliente_nombre',
        'cliente_documento',
        'metodo_pago',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'total' => 'decimal:2',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Lista de precios utilizada en la venta.
     */
    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    /**
     * Detalles de la venta (items).
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    /**
     * Scope para ventas pendientes de sincronizar.
     */
    public function scopePendientes($query)
    {
        return $query->where('sincronizado', false);
    }

    /**
     * Scope para ventas sincronizadas.
     */
    public function scopeSincronizadas($query)
    {
        return $query->where('sincronizado', true);
    }

    /**
     * Marca la venta como sincronizada.
     */
    public function marcarSincronizada(): void
    {
        $this->update([
            'sincronizado' => true,
            'sincronizado_at' => now(),
        ]);
    }

    /**
     * Genera el siguiente número de venta.
     */
    public static function generarNumeroVenta(): string
    {
        $ultimaVenta = self::latest('id')->first();
        $numero = $ultimaVenta ? $ultimaVenta->id + 1 : 1;

        $pdvId = Configuracion::get('punto_de_venta_id', '00');

        return sprintf('PDV%02d-%06d', $pdvId, $numero);
    }
}
