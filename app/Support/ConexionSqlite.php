<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ConexionSqlite
{
    /**
     * La pantalla, la cola y el scheduler escriben en el mismo SQLite. Con transacciones
     * DEFERRED, una que lee y después escribe (abrir la caja, registrar una venta) falla
     * al instante con "database is locked" si otro proceso escribió en el medio: SQLite no
     * aplica busy_timeout a esa carrera. Pasó en la caja 2 al abrir la caja justo cuando
     * corría el sync de cada minuto. IMMEDIATE reserva la escritura al empezar y la
     * segunda transacción espera en vez de romper.
     *
     * NativePHP ya abrió la conexión cuando esto corre: el purge hace que la próxima
     * consulta reconecte con esta config. transaction_mode solo lo respeta Laravel con
     * PHP 8.4 (la app de escritorio); en las cajas clásicas con 8.2 queda el busy_timeout.
     */
    public static function ajustarEscrituras(?string $conexion): void
    {
        $config = config("database.connections.{$conexion}");

        if (($config['driver'] ?? null) !== 'sqlite' || ($config['database'] ?? null) === ':memory:') {
            return;
        }

        config([
            "database.connections.{$conexion}.transaction_mode" => 'IMMEDIATE',
            "database.connections.{$conexion}.busy_timeout" => 10000,
        ]);

        DB::purge($conexion);
    }
}
