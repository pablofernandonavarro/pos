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
        Sucursal::forceCreate(['id' => 3, 'nombre' => 'Centro', 'is_central' => false]);

        $this->impresora = new ImpresoraFalsaRemitos;
        $this->app->instance(ImpresoraTickets::class, $this->impresora);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function remitoDelManager(int $id, string $numero, int $destinoId, string $destino, string $estado, array $items, ?string $remitidoAt = null, ?string $confirmadoAt = null): array
    {
        return [
            'id' => $id, 'numero' => $numero, 'destino_sucursal_id' => $destinoId, 'destino' => $destino,
            'estado' => $estado, 'items' => $items, 'observaciones' => null,
            'remitido_at' => $remitidoAt ?? now()->toIso8601String(), 'confirmado_at' => $confirmadoAt,
        ];
    }

    public function test_crear_un_remito_sincroniza_la_copia_local_con_lo_que_confirma_el_manager(): void
    {
        $p = $this->producto(['id' => 500, 'nombre' => 'Zapatillas', 'codigo_interno' => 'ZAP1', 'stock' => 10]);

        Http::fake([
            '*/pos/remitos/configuracion*' => Http::response(['ruta_directa' => true]),
            '*/pos/remitos' => Http::response(['data' => ['id' => 900, 'numero' => 'R-001']]),
            '*/pos/remitos/enviados*' => Http::response(['data' => [
                $this->remitoDelManager(900, 'R-001', 2, 'Central', 'remitido', [
                    ['product_id' => 500, 'nombre' => 'Zapatillas', 'codigo' => 'ZAP1', 'cantidad' => 4],
                ]),
            ]]),
        ]);

        Livewire::test(RemitoNuevo::class)
            ->set('destinoSucursalId', 2)
            ->call('agregarProducto', $p->id, '4')
            ->set('observaciones', 'Urgente')
            ->call('crear')
            ->assertSet('error', null);

        $remito = RemitoSaliente::find(900);
        $this->assertNotNull($remito, 'no se sincronizó la copia local');
        $this->assertSame('R-001', $remito->numero);
        $this->assertSame(2, $remito->destino_sucursal_id);
        $this->assertSame('Central', $remito->destino_nombre);
        $this->assertSame('remitido', $remito->estado);
        $this->assertSame(4, $remito->total_unidades);
        $this->assertSame('Zapatillas', $remito->items[0]['nombre']);
    }

    public function test_si_el_manager_rechaza_el_remito_no_hay_nada_que_sincronizar(): void
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

    public function test_la_pantalla_sincroniza_al_entrar_y_lista_lo_mas_reciente_primero(): void
    {
        Http::fake(['*/pos/remitos/enviados*' => Http::response(['data' => [
            $this->remitoDelManager(1, 'R-001', 2, 'Central', 'remitido', [['product_id' => 1, 'nombre' => 'A', 'codigo' => 'A1', 'cantidad' => 1]], now()->subDay()->toIso8601String()),
            $this->remitoDelManager(2, 'R-002', 2, 'Central', 'confirmado', [['product_id' => 2, 'nombre' => 'B', 'codigo' => 'B1', 'cantidad' => 2]], now()->toIso8601String(), now()->toIso8601String()),
        ]])]);

        Livewire::test(RemitosEnviados::class)
            ->assertSet('error', null)
            ->assertSeeInOrder(['R-002', 'R-001']);

        $this->assertSame(2, RemitoSaliente::count());
    }

    public function test_filtra_por_sucursal_destino(): void
    {
        Http::fake(['*/pos/remitos/enviados*' => Http::response(['data' => []])]);

        RemitoSaliente::insert([
            ['id' => 1, 'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central', 'estado' => 'remitido', 'items' => '[]', 'total_unidades' => 1, 'enviado_at' => now()],
            ['id' => 2, 'numero' => 'R-002', 'destino_sucursal_id' => 3, 'destino_nombre' => 'Centro', 'estado' => 'remitido', 'items' => '[]', 'total_unidades' => 1, 'enviado_at' => now()],
        ]);

        Livewire::test(RemitosEnviados::class)
            ->assertSee('R-001')->assertSee('R-002')
            ->set('sucursalId', '2')
            ->assertSee('R-001')->assertDontSee('R-002');
    }

    public function test_filtra_por_estado(): void
    {
        Http::fake(['*/pos/remitos/enviados*' => Http::response(['data' => []])]);

        RemitoSaliente::insert([
            ['id' => 1, 'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central', 'estado' => 'remitido', 'items' => '[]', 'total_unidades' => 1, 'enviado_at' => now()],
            ['id' => 2, 'numero' => 'R-002', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central', 'estado' => 'confirmado', 'items' => '[]', 'total_unidades' => 1, 'enviado_at' => now()],
        ]);

        Livewire::test(RemitosEnviados::class)
            ->set('estado', 'confirmado')
            ->assertDontSee('R-001')->assertSee('R-002');
    }

    public function test_imprime_directo_en_la_app_de_escritorio(): void
    {
        Http::fake(['*/pos/remitos/enviados*' => Http::response(['data' => []])]);

        RemitoSaliente::create([
            'id' => 1, 'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central', 'estado' => 'remitido',
            'items' => [['product_id' => 1, 'nombre' => 'A', 'codigo' => 'A1', 'cantidad' => 1]],
            'total_unidades' => 1, 'enviado_at' => now(),
        ]);

        Livewire::test(RemitosEnviados::class)
            ->call('imprimir', 1)
            ->assertSet('error', null)
            ->assertSet('mensaje', 'Remito R-001 enviado a la impresora.');

        $this->assertCount(1, $this->impresora->impresos);
        $this->assertStringContainsString('Central', $this->impresora->impresos[0]['html']);
    }

    public function test_en_instalacion_clasica_el_comprobante_se_abre_por_el_navegador(): void
    {
        $this->app->instance(ImpresoraTickets::class, new ImpresoraNavegador);

        $remito = RemitoSaliente::create([
            'id' => 1, 'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino_nombre' => 'Central', 'estado' => 'remitido',
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
