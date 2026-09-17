<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.8.7 publicó `crear_remitos_salientes` con un esquema viejo (id autoincremental, sin
 * `estado` ni `confirmado_at`). v1.8.8 REESCRIBIÓ ese mismo archivo con el esquema
 * correcto en vez de agregar una migración nueva. Error que no hay que repetir: Laravel
 * identifica una migración como "ya corrida" por el NOMBRE DEL ARCHIVO, sin mirar el
 * contenido, así que las cajas que ya habían corrido v1.8.7 quedaron con la tabla vieja y
 * `migrate --force` nunca las tocó al actualizar a v1.8.8. El síntoma fue un 500
 * ("no such column: estado") al entrar a Remitos enviados, porque el código nuevo
 * (RemitosSalientesService::sincronizar) ya asume el esquema nuevo.
 *
 * Esta migración detecta y repara ese estado viejo. Como remitos_salientes es solo una
 * copia local que sincronizar() repuebla entera desde el Manager en cada entrada a la
 * pantalla, no hace falta preservar filas: alcanza con dropear y recrear la tabla.
 *
 * No volver a editar crear_remitos_salientes.php: una instalación limpia la corre tal
 * cual y queda bien. Cualquier arreglo futuro a esta tabla va en una migración nueva,
 * como esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sin columna `estado` con la tabla ya creada = esquema viejo de v1.8.7.
        if (Schema::hasTable('remitos_salientes') && ! Schema::hasColumn('remitos_salientes', 'estado')) {
            Schema::drop('remitos_salientes');
        }

        // No existe (por el drop de arriba, o porque nunca corrió ninguna migración):
        // recrearla completa. Si ya existe con `estado` (instalación limpia o test),
        // no hay nada que hacer.
        if (! Schema::hasTable('remitos_salientes')) {
            Schema::create('remitos_salientes', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('numero');
                $table->unsignedBigInteger('destino_sucursal_id');
                $table->string('destino_nombre');
                $table->string('estado');
                $table->json('items');
                $table->unsignedInteger('total_unidades')->default(0);
                $table->text('observaciones')->nullable();
                $table->dateTime('enviado_at');
                $table->dateTime('confirmado_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos_salientes');
    }
};
