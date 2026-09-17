<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Red de seguridad contra el mismo bug que rompió Remitos enviados en producción: una
 * migración ya publicada se reescribió en vez de agregar una nueva, y las cajas que ya
 * la habían corrido quedaron con la tabla vieja sin que ningún test lo detectara (los
 * tests usan RefreshDatabase, que siempre corre las migraciones actuales desde cero).
 *
 * Esto no reproduce ese escenario (para eso existe RepararRemitosSalientesTest, específico
 * de esa tabla) — es más simple: fija en un test las columnas que el código de negocio
 * asume que existen en las tablas más sensibles (login de cajeros, turno de caja, ventas),
 * para que romper esa suposición sin querer falle acá antes que en producción.
 */
class EsquemaCriticoTest extends TestCase
{
    use RefreshDatabase;

    public function test_columnas_criticas_de_cajeros(): void
    {
        foreach (['id', 'nombre', 'rol', 'pin_hash'] as $columna) {
            $this->assertTrue(Schema::hasColumn('cajeros', $columna), "falta cajeros.{$columna}");
        }
    }

    public function test_columnas_criticas_de_turnos_caja(): void
    {
        foreach (['id', 'numero', 'cajero', 'cajero_id', 'fondo_inicial', 'abierto_at', 'cerrado_at'] as $columna) {
            $this->assertTrue(Schema::hasColumn('turnos_caja', $columna), "falta turnos_caja.{$columna}");
        }
    }

    public function test_columnas_criticas_de_ventas(): void
    {
        foreach (['id', 'turno_caja_id', 'cajero', 'total', 'sincronizado', 'uuid'] as $columna) {
            $this->assertTrue(Schema::hasColumn('ventas', $columna), "falta ventas.{$columna}");
        }
    }
}
