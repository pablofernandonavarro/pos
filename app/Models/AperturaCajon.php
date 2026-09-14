<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Apertura manual del cajón, con su motivo. */
class AperturaCajon extends Model
{
    protected $table = 'aperturas_cajon';

    protected $fillable = ['turno_caja_id', 'cajero', 'motivo', 'abrio'];

    protected function casts(): array
    {
        return ['abrio' => 'boolean'];
    }
}
