<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local de los remitos que esta sucursal mandó a otras. El Manager es la fuente de
 * verdad (ahí se descuenta y suma stock, y ahí vive el estado real); esto existe para que
 * la caja pueda mostrar su propio historial, filtrarlo y reimprimir sin depender de la red.
 *
 * Mismo id que en el Manager (no autoincremental): así sincronizar() puede hacer upsert
 * sin duplicar filas, igual que remitos_entrantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remitos_salientes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('numero');
            $table->unsignedBigInteger('destino_sucursal_id');
            $table->string('destino_nombre');
            // remitido, confirmado o cancelado (App\Enums\EstadoRemito del Manager).
            $table->string('estado');
            $table->json('items');
            $table->unsignedInteger('total_unidades')->default(0);
            $table->text('observaciones')->nullable();
            $table->dateTime('enviado_at');
            $table->dateTime('confirmado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos_salientes');
    }
};
