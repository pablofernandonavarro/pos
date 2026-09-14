<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2: cajeros con PIN (copia local del Manager), devoluciones/anulaciones y
 * descuentos manuales autorizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajeros', function (Blueprint $table) {
            // Mismo id que en el Manager.
            $table->unsignedBigInteger('id')->primary();
            $table->string('nombre', 100);
            $table->string('rol', 20)->default('cajero');
            $table->string('pin_hash');
            $table->timestamps();
        });

        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->unsignedBigInteger('cajero_id')->nullable()->after('cajero');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->decimal('descuento_manual', 12, 2)->default(0)->after('descuento');
            $table->string('descuento_autorizado_por', 100)->nullable()->after('descuento_manual');
        });

        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->foreignId('turno_caja_id')->constrained('turnos_caja');
            $table->string('numero', 30);
            $table->string('tipo', 10); // anulacion | parcial
            $table->string('motivo', 200);
            $table->string('reintegro', 20); // efectivo | medio_original
            $table->decimal('total', 12, 2);
            $table->string('autorizado_por', 100);
            $table->boolean('sincronizado')->default(false);
            $table->dateTime('sincronizado_at')->nullable();
            $table->timestamps();

            $table->index('sincronizado');
        });

        Schema::create('devolucion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('detalle_venta_id')->constrained('detalle_ventas');
            $table->unsignedBigInteger('product_id');
            $table->integer('cantidad');
            $table->decimal('importe', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_items');
        Schema::dropIfExists('devoluciones');

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['descuento_manual', 'descuento_autorizado_por']);
        });

        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->dropColumn('cajero_id');
        });

        Schema::dropIfExists('cajeros');
    }
};
