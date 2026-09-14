<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo (producto configurable) de cada variante. El Manager no manda el configurable
 * porque no se vende; con su código y nombre la caja puede buscar "CONF-4301" y ofrecer
 * elegir color y talle, y mostrar a qué prenda pertenece la variante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->string('modelo_codigo')->nullable()->index();
            $table->string('modelo_nombre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropIndex(['modelo_codigo']);
            $table->dropColumn(['modelo_codigo', 'modelo_nombre']);
        });
    }
};
