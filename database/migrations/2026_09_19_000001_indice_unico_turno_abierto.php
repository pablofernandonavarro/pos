<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defensa en profundidad contra dos turnos de caja abiertos a la vez.
 *
 * CajaService::crearTurno() ya lo previene a nivel de aplicación (lee turnoAbierto()
 * dentro de una transacción), y ConexionSqlite fuerza transaction_mode=IMMEDIATE, que en
 * la práctica serializa dos aperturas casi simultáneas. Pero eso es un supuesto implícito
 * sobre cómo se configura la conexión, no algo que la base misma impida. Esto agrega esa
 * segunda red de seguridad a nivel de esquema.
 *
 * No se puede expresar como UNIQUE normal sobre `cerrado_at`: SQL trata cada NULL como
 * distinto de cualquier otro NULL, así que un UNIQUE ahí permitiría infinitos turnos
 * abiertos (todos con cerrado_at NULL) sin quejarse. La forma correcta es un índice único
 * parcial sobre una expresión constante, filtrado a las filas abiertas: como el valor
 * indexado nunca es NULL para esas filas, el UNIQUE sí aplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX turnos_caja_un_turno_abierto ON turnos_caja ((1)) WHERE cerrado_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIndexIfExists('turnos_caja_un_turno_abierto');
    }
};
