<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });

        // Insertar configuración inicial
        DB::table('configuracion')->insert([
            ['clave' => 'manager_api_url', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'punto_de_venta_id', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'punto_de_venta_secret', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'access_token', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'sucursal_id', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'sucursal_nombre', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'pdv_nombre', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'ultima_sincronizacion_productos', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'ultima_sincronizacion_precios', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'ultima_sincronizacion_stock', 'valor' => null, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'configurado', 'valor' => '0', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
