<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aperturas manuales del cajón (sin venta ni cobro). Salen en el informe X/Z: un cajón que
 * se abre seguido sin motivo es lo primero que se mira ante un faltante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aperturas_cajon', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja');
            $table->string('cajero', 100)->nullable();
            $table->string('motivo', 200);
            $table->boolean('abrio')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aperturas_cajon');
    }
};
