<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ListaPrecio extends Model
{
    protected $table = 'listas_precios';

    protected $fillable = [
        'nombre',
        'factor',
        'es_default',
        'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:4',
            'es_default' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Precios específicos de esta lista.
     */
    public function precios(): HasMany
    {
        return $this->hasMany(Precio::class);
    }

    /**
     * Obtiene la lista de precios por defecto.
     */
    public static function getDefault(): ?self
    {
        return self::where('es_default', true)->first();
    }

    /**
     * Establece esta lista como predeterminada.
     */
    public function setAsDefault(): void
    {
        DB::transaction(function () {
            self::query()->update(['es_default' => false]);
            $this->update(['es_default' => true]);
        });
    }
}
