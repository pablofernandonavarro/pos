<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica. La caja no habla con AFIP: marca la venta para facturar con los
 * datos del cliente, el Manager pide el CAE, y acá se guarda lo que devuelve (número, CAE,
 * QR) para imprimir la factura aun sin conexión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->boolean('facturar')->default(false);
            $table->unsignedSmallInteger('receptor_condicion_iva')->nullable();
            $table->unsignedSmallInteger('receptor_doc_tipo')->nullable();
            $table->string('receptor_doc_nro', 20)->nullable();
            // pendiente / autorizado / rechazado. null = no se factura.
            $table->string('comprobante_estado', 20)->nullable()->index();
            $table->json('comprobante')->nullable();
        });

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->string('comprobante_estado', 20)->nullable()->index();
            $table->json('comprobante')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropIndex(['comprobante_estado']);
            $table->dropColumn(['facturar', 'receptor_condicion_iva', 'receptor_doc_tipo', 'receptor_doc_nro', 'comprobante_estado', 'comprobante']);
        });

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->dropIndex(['comprobante_estado']);
            $table->dropColumn(['comprobante_estado', 'comprobante']);
        });
    }
};
