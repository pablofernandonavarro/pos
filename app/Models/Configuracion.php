<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'configuracion';

    protected $fillable = [
        'clave',
        'valor',
    ];

    /**
     * Obtiene el valor de una configuración.
     */
    public static function get(string $clave, mixed $default = null): mixed
    {
        $config = self::where('clave', $clave)->first();

        return $config?->valor ?? $default;
    }

    /**
     * Establece el valor de una configuración.
     */
    public static function set(string $clave, mixed $valor): void
    {
        self::updateOrCreate(
            ['clave' => $clave],
            ['valor' => $valor]
        );
    }

    /**
     * Verifica si el POS está configurado.
     */
    public static function isConfigured(): bool
    {
        return (bool) self::get('configurado', false);
    }

    /**
     * Verifica si hay un token de acceso válido.
     */
    public static function hasValidToken(): bool
    {
        return ! empty(self::get('access_token'));
    }

    /**
     * Obtiene todas las configuraciones como array.
     */
    public static function getAllConfig(): array
    {
        return self::query()->pluck('valor', 'clave')->toArray();
    }
}
