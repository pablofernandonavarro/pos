<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('tipo'); // venta, ajuste, entrada, salida
            $table->integer('cantidad');
            $table->string('referencia')->nullable();
            $table->timestamp('fecha');
            $table->boolean('sincronizado')->default(false);
            $table->timestamp('sincronizado_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('productos');
            $table->index('sincronizado');
            $table->index(['product_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
    }
};
