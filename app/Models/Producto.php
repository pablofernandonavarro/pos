<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'nombre',
        'codigo_interno',
        'codigo_barras',
        'busqueda',
        'precio',
        'costo',
        'stock',
        'stock_critico',
        'imagen_url',
        'descripcion_web',
        'marca',
        'color',
        'n_talle',
        'genero',
        'n_grupo',
        'n_subgrupo',
        'n_temporada',
        'product_type',
        'parent_id',
        'es_vendible',
        'activo',
        'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'costo' => 'decimal:2',
            'stock' => 'integer',
            'stock_critico' => 'integer',
            'es_vendible' => 'boolean',
            'activo' => 'boolean',
            'sincronizado_at' => 'datetime',
        ];
    }

    /**
     * Producto padre (para variantes).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'parent_id');
    }

    /**
     * Variantes del producto.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Producto::class, 'parent_id');
    }

    /**
     * Movimientos de stock del producto.
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'product_id');
    }

    /**
     * Scope para productos vendibles.
     */
    public function scopeVendible($query)
    {
        return $query->where('es_vendible', true)->where('activo', true);
    }

    /**
     * Scope para productos simples.
     */
    public function scopeSimple($query)
    {
        return $query->where('product_type', 'simple');
    }

    /**
     * Scope para productos configurables.
     */
    public function scopeConfigurable($query)
    {
        return $query->where('product_type', 'configurable');
    }

    /**
     * Scope para buscar productos por texto (usa FTS5).
     */
    public function scopeSearch($query, string $search)
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        // Búsqueda exacta por código. COLLATE NOCASE y no strtolower(): los códigos se
        // guardan en mayúsculas ("ART-5626") y el = de SQLite distingue mayúsculas, así
        // que pasar el texto a minúsculas hacía que el código exacto nunca coincidiera.
        // El grupo evita que el OR se coma las condiciones del scope vendible().
        $exactMatch = $query->clone()
            ->where(function ($q) use ($search) {
                $q->whereRaw('codigo_interno = ? COLLATE NOCASE', [$search])
                    ->orWhereRaw('codigo_barras = ? COLLATE NOCASE', [$search]);
            })
            ->first();

        if ($exactMatch) {
            return $query->where('id', $exactMatch->id);
        }

        $consultaFts = self::consultaFts($search);

        $ids = $consultaFts === null ? [] : DB::select('
            SELECT id FROM productos_fts
            WHERE busqueda MATCH ?
            ORDER BY rank
            LIMIT 50
        ', [$consultaFts]);

        $productIds = collect($ids)->pluck('id');

        if ($productIds->isEmpty()) {
            // Fallback: búsqueda LIKE
            return $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('codigo_interno', 'like', "%{$search}%")
                    ->orWhere('codigo_barras', 'like', "%{$search}%");
            });
        }

        return $query->whereIn('id', $productIds);
    }

    /**
     * Arma una consulta FTS5 segura a partir de lo que tipeó el usuario.
     *
     * El texto no puede ir directo a MATCH: FTS5 tiene su propia sintaxis y un guion,
     * dos puntos o comillas se interpretan como operadores. "ART-5626" reventaba con
     * "no such column: 5626", y la mayoría de los códigos del catálogo llevan guion.
     * Cada palabra va entre comillas (frase literal) con * para que matchee por prefijo
     * mientras se tipea. Las palabras sin letras ni números se descartan porque una
     * frase vacía también es error de sintaxis en FTS5.
     */
    private static function consultaFts(string $texto): ?string
    {
        $terminos = array_filter(
            preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $t) => preg_match('/[\p{L}\p{N}]/u', $t) === 1
        );

        if ($terminos === []) {
            return null;
        }

        return implode(' ', array_map(
            fn (string $t) => '"'.str_replace('"', '""', $t).'"*',
            $terminos
        ));
    }

    /**
     * Verifica si hay stock disponible.
     */
    public function hasStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Verifica si está en stock crítico.
     */
    public function isCriticalStock(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->stock_critico;
    }

    /**
     * Obtiene el precio efectivo del producto según la lista de precios.
     */
    public function getPrecioEfectivo(?int $listaId = null): float
    {
        if (! $listaId) {
            $listaDefault = ListaPrecio::where('es_default', true)->first();
            $listaId = $listaDefault?->id;
        }

        if (! $listaId) {
            return (float) $this->precio;
        }

        // Buscar override en la tabla de precios
        $precio = Precio::where('lista_precio_id', $listaId)
            ->where('product_id', $this->id)
            ->where(function ($query) {
                $query->whereNull('vigencia_desde')
                    ->orWhere('vigencia_desde', '<=', now()->toDateString());
            })
            ->where(function ($query) {
                $query->whereNull('vigencia_hasta')
                    ->orWhere('vigencia_hasta', '>=', now()->toDateString());
            })
            ->first();

        if ($precio) {
            return (float) $precio->precio_override;
        }

        // Aplicar factor de la lista
        $lista = ListaPrecio::find($listaId);

        return (float) ($this->precio * ($lista?->factor ?? 1));
    }

    /**
     * Reduce el stock del producto.
     */
    public function reducirStock(int $cantidad): void
    {
        $this->decrement('stock', $cantidad);
    }

    /**
     * Aumenta el stock del producto.
     */
    public function aumentarStock(int $cantidad): void
    {
        $this->increment('stock', $cantidad);
    }

    /**
     * Verifica si es un producto simple.
     */
    public function isSimple(): bool
    {
        return $this->product_type === 'simple';
    }

    /**
     * Verifica si es un producto configurable.
     */
    public function isConfigurable(): bool
    {
        return $this->product_type === 'configurable';
    }
}
