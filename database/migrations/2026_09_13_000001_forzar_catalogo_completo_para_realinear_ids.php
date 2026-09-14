<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las cajas instaladas antes de este cambio tienen el catálogo con ids propios de SQLite
 * en vez de los del Manager. Borrar la marca de la última sincronización hace que el
 * próximo pull de stock baje el catálogo entero, y con el catálogo entero SyncService
 * realinea los ids. Mientras tanto las ventas quedan retenidas en la caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuracion')
            ->where('clave', 'ultima_sincronizacion_productos')
            ->delete();
    }

    public function down(): void
    {
        // Nada que deshacer: la marca se vuelve a escribir en la próxima sincronización.
    }
};
