<?php

namespace Tests\Feature;

use App\Livewire\Pos\RemitoNuevo;
use App\Models\Configuracion;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RemitoNuevoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::set('sucursal_id', '1');
        Configuracion::set('manager_api_url', 'http://manager.fake/api/v1');
        Configuracion::set('access_token', 'tok');

        Sucursal::forceCreate(['id' => 1, 'nombre' => 'Villa Bosh', 'is_central' => false]);
        Sucursal::forceCreate(['id' => 2, 'nombre' => 'Central', 'is_central' => true]);

        Http::fake(['*/pos/remitos/configuracion*' => Http::response(['ruta_directa' => true])]);
    }

    /**
     * El botón "+" manda el value de un <input> HTML (siempre string), no viene de
     * wire:model. Livewire no lo castea solo: si el método pide int, tira un 500.
     */
    public function test_agregar_producto_acepta_la_cantidad_como_string_del_input(): void
    {
        $p = $this->producto(['id' => 500, 'stock' => 10]);

        Livewire::test(RemitoNuevo::class)
            ->call('agregarProducto', $p->id, '3')
            ->assertSet('items', [500 => 3])
            ->call('agregarProducto', $p->id, '')
            ->assertSet('items', [500 => 3], 'una cantidad vacía no debe sumar nada ni tirar error');
    }

    public function test_crear_remito_redirige_a_la_pantalla_de_remitos(): void
    {
        $p = $this->producto(['id' => 500, 'stock' => 10]);

        Http::fake([
            '*/pos/remitos/configuracion*' => Http::response(['ruta_directa' => true]),
            '*/pos/remitos' => Http::response(['data' => ['numero' => 'R-001']]),
        ]);

        Livewire::test(RemitoNuevo::class)
            ->set('destinoSucursalId', 2)
            ->call('agregarProducto', $p->id, '2')
            ->call('crear')
            ->assertSet('error', null)
            ->assertRedirect(route('pos.remitos'));
    }
}
