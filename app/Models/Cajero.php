<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Copia local de un cajero del Manager. El PIN viene hasheado (bcrypt) y se verifica acá,
 * así se puede abrir la caja y autorizar sin conexión.
 */
class Cajero extends Model
{
    protected $table = 'cajeros';

    public $incrementing = false;

    protected $fillable = ['id', 'nombre', 'rol', 'pin_hash', 'foto_url'];

    protected $hidden = ['pin_hash'];

    public function esSupervisor(): bool
    {
        return $this->rol === 'supervisor';
    }
}
