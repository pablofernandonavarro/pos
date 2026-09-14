<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local de los remitos que vienen en camino a esta sucursal. Existe para que la
 * alerta del header y la pantalla de recepción se lean de SQLite, sin tocar la red: con
 * el Manager caído la pantalla de venta no se puede colgar esperando.
 *
 * No es la fuente de verdad: se reemplaza entera en cada sincronización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remitos_entrantes', function (Blueprint $table) {
            // Mismo id que en el Manager.
            $table->unsignedBigInteger('id')->primary();
            $table->string('numero');
            $table->string('origen');
            $table->dateTime('remitido_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->json('items');
            $table->unsignedInteger('total_unidades')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos_entrantes');
    }
};
