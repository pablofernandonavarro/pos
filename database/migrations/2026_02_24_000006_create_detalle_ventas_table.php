<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->integer('cantidad');
            $table->decimal('precio_unitario', 10, 3);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('productos');
            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_ventas');
    }
};
