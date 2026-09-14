<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local de clientes (se reemplaza entera desde el Manager, con el saldo) y los cobros
 * de cuenta corriente hechos en esta caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            // El id es el del Manager: viaja en ventas y cobros.
            $table->unsignedBigInteger('id')->primary();
            $table->string('nombre', 150)->index();
            $table->unsignedSmallInteger('doc_tipo')->default(99);
            $table->string('documento', 20)->nullable()->index();
            $table->unsignedSmallInteger('condicion_iva')->default(5);
            $table->string('telefono', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('cuenta_corriente')->default(false);
            $table->decimal('limite_credito', 16, 2)->nullable();
            // Saldo según el Manager al pedir la lista (reloj de la caja en `sincronizado_at`).
            $table->decimal('saldo', 16, 2)->default(0);
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
        });

        Schema::table('ventas', function (Blueprint $table): void {
            // Sin FK: la lista de clientes se reemplaza entera y una venta vieja puede ser de un
            // cliente que ya no está.
            $table->unsignedBigInteger('cliente_id')->nullable()->index();
        });

        Schema::create('cobros_cuenta_corriente', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 30);
            $table->unsignedBigInteger('cliente_id')->index();
            $table->string('cliente_nombre', 150);
            $table->foreignId('turno_caja_id')->constrained('turnos_caja');
            $table->string('medio', 20);
            $table->decimal('importe', 12, 2);
            $table->string('cajero', 100)->nullable();
            $table->boolean('sincronizado')->default(false)->index();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_cuenta_corriente');

        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropIndex(['cliente_id']);
            $table->dropColumn('cliente_id');
        });

        Schema::dropIfExists('clientes');
    }
};
