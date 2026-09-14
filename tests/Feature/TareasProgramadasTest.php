<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class TareasProgramadasTest extends TestCase
{
    /**
     * Con el vencimiento por defecto (24 h), una caja que se apagó en medio de un sync
     * quedaba un día entero sin bajar stock.
     */
    public function test_ninguna_tarea_deja_un_candado_de_mas_de_15_minutos(): void
    {
        $eventos = app(Schedule::class)->events();

        $this->assertNotEmpty($eventos);

        foreach ($eventos as $evento) {
            $this->assertTrue($evento->withoutOverlapping, "{$evento->command} tiene que evitar solaparse");
            $this->assertLessThanOrEqual(15, $evento->expiresAt, "{$evento->command}: candado de {$evento->expiresAt} minutos");
        }
    }
}
