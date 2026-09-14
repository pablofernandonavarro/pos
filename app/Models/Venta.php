<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'uuid',
        'lista_precio_id',
        'turno_caja_id',
        'cajero',
        'numero_venta',
        'fecha',
        'subtotal',
        'descuento',
        'descuento_manual',
        'descuento_autorizado_por',
        'total',
        'sincronizado',
        'sincronizado_at',
        'cliente_nombre',
        'cliente_documento',
        'metodo_pago',
        'cliente_id',
        'facturar',
        'receptor_condicion_iva',
        'receptor_doc_tipo',
        'receptor_doc_nro',
        'comprobante_estado',
        'comprobante',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'descuento_manual' => 'decimal:2',
            'total' => 'decimal:2',
            'sincronizado' => 'boolean',
            'sincronizado_at' => 'datetime',
            'facturar' => 'boolean',
            'receptor_condicion_iva' => 'integer',
            'receptor_doc_tipo' => 'integer',
            'comprobante' => 'array',
        ];
    }

    /** Tiene factura con CAE: el ticket sale como comprobante fiscal. */
    public function facturaAutorizada(): bool
    {
        return $this->comprobante_estado === 'autorizado' && ! empty($this->comprobante['cae']);
    }

    /**
     * El uuid se genera en la caja y es la clave de idempotencia contra el Manager:
     * permite reintentar un push sin duplicar la venta.
     */
    protected static function booted(): void
    {
        static::creating(function (self $venta): void {
            $venta->uuid ??= (string) Str::uuid();
        });
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

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class);
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
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
