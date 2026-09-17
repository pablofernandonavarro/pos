<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién vendió cada venta puntual, distinto de quién abrió el turno (turnos_caja.cajero /
 * cajero_id): varias vendedoras pueden usar la misma caja abierta a lo largo del día, y
 * hasta ahora no había forma de saber cuál hizo cada venta. Se llama "vendedor" y no
 * "cajero" a propósito, para no confundirlo con el concepto ya existente en turnos_caja.
 *
 * Sin FK hacia cajeros por el mismo motivo que turnos_caja.cajero_id no la tiene: cajeros
 * es una copia sincronizada que el Manager puede reemplazar; se desnormaliza el nombre
 * para que tickets y reportes no dependan de un join que puede no encontrar al cajero.
 *
 * Nullable: ventas viejas no lo tienen (fallback a `cajero` del turno), e instalaciones
 * sin cajeros cargados en el Manager (AutorizacionService::hayCajeros() === false) siguen
 * vendiendo sin PIN individual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('vendedor_id')->nullable()->after('cajero');
            $table->string('vendedor_nombre', 100)->nullable()->after('vendedor_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['vendedor_id', 'vendedor_nombre']);
        });
    }
};
