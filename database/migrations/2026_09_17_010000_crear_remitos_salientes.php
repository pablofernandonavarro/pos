<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro local de los remitos que esta caja mandó a otra sucursal. El Manager es la
 * fuente de verdad (ahí se descuenta y suma stock), esto existe solo para que la caja
 * pueda mostrar su propio historial y reimprimir el comprobante sin depender de la red.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remitos_salientes', function (Blueprint $table) {
            $table->id();
            // Numero que asignó el Manager al crear el remito.
            $table->string('numero');
            $table->unsignedBigInteger('destino_sucursal_id');
            $table->string('destino_nombre');
            $table->json('items');
            $table->unsignedInteger('total_unidades')->default(0);
            $table->text('observaciones')->nullable();
            $table->dateTime('enviado_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos_salientes');
    }
};
