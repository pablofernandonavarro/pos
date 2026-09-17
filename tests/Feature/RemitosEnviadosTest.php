<?php

namespace Tests\Feature;

use App\Contracts\ImpresoraTickets;
use App\Livewire\Pos\RemitoNuevo;
use App\Livewire\Pos\RemitosEnviados;
use App\Models\Configuracion;
use App\Models\RemitoSaliente;
use App\Models\Sucursal;
use App\Services\Impresion\ImpresoraNavegador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RemitosEnviadosTest extends TestCase
{
    use RefreshDatabase;

    private ImpresoraFalsaRemitos $impresora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarCaja();
        Configuracion::set('sucursal_id', '1');

        Sucursal::forceCreate(['id' => 1, 'nombre' => 'Villa Bosh', 'is_central' => false]);
        Sucursal::forceCreate(['id' => 2, 'nombre' => 'Central', 'is_central' => true]);

        $this->impresora = new ImpresoraFalsaRemitos;
        $this->app->instance(ImpresoraTickets::class, $this->impresora);
    }

    public function test_crear_un_remito_guarda_la_copia_local_para_el_historial(): void
    {
        $p = $this->producto(['id' => 500, 'nombre' => 'Zapatillas', 'codigo_interno' => 'ZAP1', 'stock' => 10]);

        Http::fake([
            '*/pos/remitos/configuracion*' => Http::response(['ruta_directa' => true]),
            '*/pos/remitos' => Http::response(['data' => ['numero' => 'R-001']]),
        ]);

        Livewire::test(RemitoNuevo::class)
            ->set('destinoSucursalId', 2)
            ->call('agregarProducto', $p->id, '4')
            ->set('observaciones', 'Urgente')
            ->call('crear')
            ->assertSet('error', null);

        $remito = RemitoSaliente::first();
        $this->assertNotNull($remito, 'no se guardó la copia local');
        $this->assertSame('R-001', $remito->numero);
        $this->assertSame(2, $remito->destino_sucursal_id);
        $this->assertSame('Central', $remito->destino_nombre);
        $this->assertSame(4, $remito->total_unidades);
        $this->assertSame('Urgente', $remito->observaciones);
        $this->assertSame('Zapatillas', $remito->items[0]['nombre']);
        $this->assertSame('ZAP1', $remito->items[0]['codigo']);
        $this->assertSame(4, $remito->items[0]['cantidad']);
    }

    public function test_si_el_manager_rechaza_el_remito_no_se_guarda_copia_local(): void
    {
        $p = $this->producto(['id' => 500, 'stock' => 10]);

        Http::fake([
            '*/pos/remitos/configuracion*' => Http::response(['ruta_directa' => true]),
            '*/pos/remitos' => Http::response(['message' => 'Sin stock'], 422),
        ]);

        Livewire::test(RemitoNuevo::class)
            ->set('destinoSucursalId', 2)
            ->call('agregarProducto', $p->id, '4')
            ->call('crear')
            ->assertSet('error', 'Sin stock');

        $this->assertSame(0, RemitoSaliente::count());
    }

    public function test_la_pantalla_lista_los_remitos_enviados_mas_recientes_primero(): void
    {
        RemitoSaliente::create([
            'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central',
            'items' => [['product_id' => 1, 'nombre' => 'A', 'codigo' => 'A1', 'cantidad' => 1]],
            'total_unidades' => 1, 'enviado_at' => now()->subDay(),
        ]);
        RemitoSaliente::create([
            'numero' => 'R-002', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central',
            'items' => [['product_id' => 2, 'nombre' => 'B', 'codigo' => 'B1', 'cantidad' => 2]],
            'total_unidades' => 2, 'enviado_at' => now(),
        ]);

        Livewire::test(RemitosEnviados::class)
            ->assertSeeInOrder(['R-002', 'R-001']);
    }

    public function test_imprime_directo_en_la_app_de_escritorio(): void
    {
        $remito = RemitoSaliente::create([
            'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central',
            'items' => [['product_id' => 1, 'nombre' => 'A', 'codigo' => 'A1', 'cantidad' => 1]],
            'total_unidades' => 1, 'enviado_at' => now(),
        ]);

        Livewire::test(RemitosEnviados::class)
            ->call('imprimir', $remito->id)
            ->assertSet('error', null)
            ->assertSet('mensaje', 'Remito R-001 enviado a la impresora.');

        $this->assertCount(1, $this->impresora->impresos);
        $this->assertStringContainsString('Central', $this->impresora->impresos[0]['html']);
    }

    public function test_en_instalacion_clasica_el_comprobante_se_abre_por_el_navegador(): void
    {
        $this->app->instance(ImpresoraTickets::class, new ImpresoraNavegador);

        $remito = RemitoSaliente::create([
            'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central',
            'items' => [['product_id' => 1, 'nombre' => 'A', 'codigo' => 'A1', 'cantidad' => 1]],
            'total_unidades' => 1, 'enviado_at' => now(),
        ]);

        $this->get(route('pos.remito.comprobante', $remito))
            ->assertOk()
            ->assertSee('REMITO')
            ->assertSee('Central')
            ->assertSee('window.print', false);
    }
}

/** Duplica ImpresoraFalsa de TicketsEImpresionTest.php: ese archivo no siempre se carga junto a este. */
class ImpresoraFalsaRemitos implements ImpresoraTickets
{
    /** @var array<int, array{html: string, impresora: string}> */
    public array $impresos = [];

    public function puedeImprimirDirecto(): bool
    {
        return true;
    }

    public function impresoras(): array
    {
        return ['EPSON TM-T20'];
    }

    public function imprimir(string $html, string $impresora): ?string
    {
        $this->impresos[] = ['html' => $html, 'impresora' => $impresora];

        return null;
    }
}
