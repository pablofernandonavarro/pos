<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte del cobro de una venta. `monto` es lo que cubre de la venta; `importe`, lo
 * que se cobró por ese medio después de la promoción (monto − descuento).
 */
class PagoVenta extends Model
{
    protected $table = 'pagos_venta';

    public const MEDIOS = [
        'efectivo' => 'Efectivo',
        'debito' => 'Débito',
        'credito' => 'Crédito',
        'transferencia' => 'Transferencia',
        'qr' => 'QR / billetera',
        // Venta fiada: suma deuda al cliente elegido (ver CuentaCorrienteService).
        'cuenta_corriente' => 'Cuenta corriente',
    ];

    public const TARJETAS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'amex' => 'American Express',
        'cabal' => 'Cabal',
        'naranja' => 'Naranja',
        'maestro' => 'Maestro',
        'otra' => 'Otra',
    ];

    /** Medios que llevan tarjeta y banco. */
    public const CON_TARJETA = ['debito', 'credito'];

    protected $fillable = [
        'venta_id',
        'medio',
        'monto',
        'descuento',
        'importe',
        'recibido',
        'vuelto',
        'tarjeta',
        'banco',
        'cuotas',
        'promocion_id',
        'promocion_nombre',
        'referencia',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'descuento' => 'decimal:2',
            'importe' => 'decimal:2',
            'recibido' => 'decimal:2',
            'vuelto' => 'decimal:2',
            'cuotas' => 'integer',
            'promocion_id' => 'integer',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
