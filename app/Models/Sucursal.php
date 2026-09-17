<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'nombre', 'is_central'];

    protected function casts(): array
    {
        return ['is_central' => 'boolean'];
    }
}
