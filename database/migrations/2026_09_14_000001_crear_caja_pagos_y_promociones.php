<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caja y cobro: turnos con apertura y cierre Z, movimientos de efectivo, pagos múltiples
 * por venta y la copia local de las promociones bancarias del Manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('numero');
            $table->string('cajero', 100);
            $table->decimal('fondo_inicial', 12, 2)->default(0);
            $table->dateTime('abierto_at');
            $table->dateTime('cerrado_at')->nullable();
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            // Foto del resumen al cerrar: el Z tiene que poder reimprimirse igual aunque
            // después se modifique algo.
            $table->json('resumen')->nullable();
            $table->text('observaciones')->nullable();
            // Solo se marca cuando el Manager confirmó el turno ya cerrado.
            $table->boolean('sincronizado')->default(false);
            $table->dateTime('sincronizado_at')->nullable();
            $table->timestamps();

            $table->index('cerrado_at');
            $table->index('sincronizado');
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->cascadeOnDelete();
            $table->string('tipo', 10); // ingreso | retiro | gasto
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 200);
            $table->timestamps();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('turno_caja_id')->nullable()->after('lista_precio_id')->constrained('turnos_caja');
            $table->string('cajero', 100)->nullable()->after('turno_caja_id');
        });

        Schema::create('pagos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->string('medio', 20); // efectivo | debito | credito | transferencia | qr
            $table->decimal('monto', 12, 2);       // parte de la venta que cubre
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('importe', 12, 2);     // lo cobrado por este medio
            $table->decimal('recibido', 12, 2)->nullable(); // efectivo entregado por el cliente
            $table->decimal('vuelto', 12, 2)->nullable();
            $table->string('tarjeta', 30)->nullable();
            $table->string('banco', 80)->nullable();
            $table->unsignedTinyInteger('cuotas')->nullable();
            $table->unsignedBigInteger('promocion_id')->nullable();
            $table->string('promocion_nombre', 120)->nullable();
            $table->string('referencia', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('promociones_bancarias', function (Blueprint $table) {
            // Mismo id que en el Manager.
            $table->unsignedBigInteger('id')->primary();
            $table->string('nombre', 120);
            $table->string('banco', 80)->nullable();
            $table->json('medios');
            $table->json('tarjetas')->nullable();
            $table->json('dias_semana')->nullable();
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->string('modalidad', 10)->default('descuento');
            $table->decimal('porcentaje', 5, 2)->default(0);
            $table->decimal('tope', 12, 2)->nullable();
            $table->decimal('monto_minimo', 12, 2)->nullable();
            $table->unsignedTinyInteger('cuotas_sin_interes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones_bancarias');
        Schema::dropIfExists('pagos_venta');

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('turno_caja_id');
            $table->dropColumn('cajero');
        });

        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('turnos_caja');
    }
};
