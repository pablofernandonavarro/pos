<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL de la foto de perfil que el Manager sirve para cada cajero (`sync/cajeros`), para
 * mostrarla en la sesión del vendedor. Nullable: no todos tienen foto, y Managers viejos
 * no mandan el campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajeros', function (Blueprint $table) {
            $table->string('foto_url')->nullable()->after('pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cajeros', function (Blueprint $table) {
            $table->dropColumn('foto_url');
        });
    }
};
