<?php

namespace App\Models;

use App\Support\Cuit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Copia local de un cliente del Manager. El saldo real en la caja lo calcula
 * CuentaCorrienteService (este + lo que la caja movió y el Manager todavía no tiene).
 */
class Cliente extends Model
{
    protected $table = 'clientes';

    public $incrementing = false;

    protected $fillable = [
        'id', 'nombre', 'doc_tipo', 'documento', 'condicion_iva', 'telefono', 'email',
        'cuenta_corriente', 'limite_credito', 'saldo', 'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'doc_tipo' => 'integer',
            'condicion_iva' => 'integer',
            'cuenta_corriente' => 'boolean',
            'limite_credito' => 'decimal:2',
            'saldo' => 'decimal:2',
            'sincronizado_at' => 'datetime',
        ];
    }

    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        $texto = trim($texto);
        $digitos = preg_replace('/\D/', '', $texto);

        return $query->where(fn ($q) => $q->where('nombre', 'like', "%{$texto}%")
            ->when(strlen($digitos) >= 3, fn ($w) => $w->orWhere('documento', 'like', "%{$digitos}%")));
    }

    public function documentoFormateado(): ?string
    {
        if (! $this->documento) {
            return null;
        }

        return in_array($this->doc_tipo, [80, 86], true) ? Cuit::formatear($this->documento) : $this->documento;
    }
}
