<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_precio_id')->nullable()->constrained('listas_precios');
            $table->string('numero_venta')->nullable();
            $table->timestamp('fecha');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->boolean('sincronizado')->default(false);
            $table->timestamp('sincronizado_at')->nullable();
            $table->string('cliente_nombre')->nullable();
            $table->string('cliente_documento')->nullable();
            $table->string('metodo_pago')->nullable();
            $table->timestamps();

            $table->index('sincronizado');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
