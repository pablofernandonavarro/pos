<?php

namespace Tests\Feature;

use App\Support\ConexionSqlite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproduce la carrera de la caja 2: una transacción lee, otro proceso (el sync) escribe,
 * y la transacción intenta escribir. Dos conexiones al mismo archivo simulan los dos
 * procesos. El otro proceso tiene busy_timeout 0 para que el test no se quede esperando.
 */
class ConexionSqliteTest extends TestCase
{
    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $this->markTestSkipped('transaction_mode solo aplica con PHP 8.4 (app de escritorio).');
        }

        $this->archivo = tempnam(sys_get_temp_dir(), 'pos-sqlite-');

        foreach (['pantalla', 'sync'] as $nombre) {
            config(["database.connections.{$nombre}" => [
                'driver' => 'sqlite',
                'database' => $this->archivo,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'journal_mode' => 'wal',
                'busy_timeout' => 0,
            ]]);
        }

        DB::connection('sync')->statement('create table turnos (id integer primary key, numero integer)');
    }

    protected function tearDown(): void
    {
        if (isset($this->archivo)) {
            DB::purge('pantalla');
            DB::purge('sync');

            foreach (['', '-wal', '-shm'] as $sufijo) {
                @unlink($this->archivo.$sufijo);
            }
        }

        parent::tearDown();
    }

    public function test_sin_ajuste_la_escritura_del_otro_proceso_rompe_la_transaccion(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('database is locked');

        DB::connection('pantalla')->transaction(function ($pantalla) {
            $pantalla->table('turnos')->max('numero');
            DB::connection('sync')->table('turnos')->insert(['numero' => 99]);
            $pantalla->table('turnos')->insert(['numero' => 1]);
        });
    }

    public function test_con_ajuste_la_transaccion_reserva_la_escritura_y_termina(): void
    {
        ConexionSqlite::ajustarEscrituras('pantalla');
        $syncBloqueado = false;

        DB::connection('pantalla')->transaction(function ($pantalla) use (&$syncBloqueado) {
            $pantalla->table('turnos')->max('numero');

            try {
                DB::connection('sync')->table('turnos')->insert(['numero' => 99]);
            } catch (QueryException) {
                // En la caja real el sync esperaría (busy_timeout) y escribiría después.
                $syncBloqueado = true;
            }

            $pantalla->table('turnos')->insert(['numero' => 1]);
        });

        $this->assertTrue($syncBloqueado);
        $this->assertSame([1], DB::connection('sync')->table('turnos')->pluck('numero')->all());
        $this->assertSame(10000, config('database.connections.pantalla.busy_timeout'));
    }

    public function test_no_toca_la_base_en_memoria_de_los_tests(): void
    {
        config(['database.connections.memoria' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        ConexionSqlite::ajustarEscrituras('memoria');

        $this->assertArrayNotHasKey('transaction_mode', config('database.connections.memoria'));
    }
}
