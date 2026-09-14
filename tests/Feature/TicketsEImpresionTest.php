<?php

namespace Tests\Feature;

use App\Contracts\ImpresoraTickets;
use App\Livewire\Pos\Ajustes;
use App\Livewire\Pos\Caja;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Models\Configuracion;
use App\Services\CajaService;
use App\Services\Impresion\ImpresoraNavegador;
use App\Services\TicketService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TicketsEImpresionTest extends TestCase
{
    use RefreshDatabase;

    private ImpresoraFalsa $impresora;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        $this->impresora = new ImpresoraFalsa;
        $this->app->instance(ImpresoraTickets::class, $this->impresora);
    }

    private function venderAlgo(): \App\Models\Venta
    {
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['nombre' => 'Zapatillas #42', 'precio' => 1500]);

        return app(VentaService::class)->registrar([['product_id' => $p->id, 'cantidad' => 2]], [['medio' => 'efectivo', 'monto' => 3000, 'recibido' => 5000]]);
    }

    public function test_el_ticket_tiene_los_datos_de_la_venta_y_del_comercio(): void
    {
        Configuracion::set('ticket_nombre_comercio', 'Zapatería Pablo');
        Configuracion::set('ticket_cuit', '30-12345678-9');
        Configuracion::set('ticket_pie', 'Cambios con ticket');
        $venta = $this->venderAlgo();

        $html = app(TicketService::class)->htmlVenta($venta);

        foreach (['Zapatería Pablo', 'CUIT 30-12345678-9', $venta->numero_venta, 'Zapatillas #42', '2 x $1.500,00', '$3.000,00', 'Vuelto', '$2.000,00', 'Ana', 'Cambios con ticket', 'no válido como factura'] as $texto) {
            $this->assertStringContainsString($texto, $html, "falta «{$texto}»");
        }
        $this->assertStringNotContainsString('window.print', $html, 'para la impresora no lleva el diálogo del navegador');
    }

    public function test_venta_con_impresion_automatica_manda_el_ticket(): void
    {
        Configuracion::set('ticket_automatico', '1');
        Configuracion::set('impresora_ticket', 'EPSON TM-T20');
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 1000]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', null);

        $this->assertCount(1, $this->impresora->impresos);
        $this->assertSame('EPSON TM-T20', $this->impresora->impresos[0]['impresora']);
    }

    public function test_si_la_impresora_falla_la_venta_queda_registrada_y_se_avisa(): void
    {
        Configuracion::set('ticket_automatico', '1');
        Configuracion::set('impresora_ticket', 'EPSON TM-T20');
        $this->impresora->falla = 'Sin papel';
        app(CajaService::class)->abrir('Ana', 0);
        $p = $this->producto(['precio' => 1000]);

        $pantalla = Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)
            ->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', 'No se imprimió el ticket: Sin papel');

        $this->assertNotNull($pantalla->get('ultimaVentaId'));
        $this->assertSame(1, \App\Models\Venta::count());

        // Arreglada la impresora, se reimprime
        $this->impresora->falla = null;
        $pantalla->call('imprimirUltimoTicket')->assertSet('error', null);
        $this->assertCount(1, $this->impresora->impresos);
    }

    public function test_sin_impresion_automatica_no_imprime(): void
    {
        $venta = $this->venderAlgo();

        $this->assertFalse(app(TicketService::class)->imprimeAutomatico());
        $this->assertCount(0, $this->impresora->impresos);
        $this->get(route('pos.ticket', $venta))->assertOk()->assertSee('window.print', false);
    }

    public function test_informes_x_y_z_se_imprimen_desde_la_caja(): void
    {
        Configuracion::set('impresora_ticket', 'EPSON TM-T20');
        $turno = app(CajaService::class)->abrir('Ana', 100);

        Livewire::test(Caja::class)->call('imprimirInforme', $turno->id)->assertSet('error', null);
        $this->assertStringContainsString('INFORME X #1', $this->impresora->impresos[0]['html']);

        app(CajaService::class)->cerrar($turno, 100);
        Livewire::test(Caja::class)->call('imprimirInforme', $turno->id);
        $this->assertStringContainsString('INFORME Z #1', $this->impresora->impresos[1]['html']);
    }

    public function test_ajustes_guarda_y_valida(): void
    {
        Livewire::test(Ajustes::class)
            ->set('impresora', 'EPSON TM-T20')
            ->set('ticketAutomatico', true)
            ->set('nombreComercio', 'Zapatería')
            ->set('cuit', '30123456789')
            ->call('guardar')
            ->assertSet('error', null)
            ->assertHasNoErrors();

        $this->assertSame('1', Configuracion::get('ticket_automatico'));
        $this->assertSame('EPSON TM-T20', Configuracion::get('impresora_ticket'));

        Livewire::test(Ajustes::class)->set('cuit', '123')->call('guardar')->assertHasErrors(['cuit']);
        Livewire::test(Ajustes::class)->set('impresora', '')->set('ticketAutomatico', true)->call('guardar')
            ->assertSet('error', 'Elegí la impresora para imprimir automáticamente.');
    }

    public function test_ajustes_imprime_una_prueba(): void
    {
        Livewire::test(Ajustes::class)->set('impresora', 'EPSON TM-T20')->call('imprimirPrueba')->assertSet('error', null);

        $this->assertStringContainsString('PRUEBA DE IMPRESIÓN', $this->impresora->impresos[0]['html']);
    }

    public function test_en_la_instalacion_clasica_no_hay_impresion_automatica(): void
    {
        $this->app->instance(ImpresoraTickets::class, new ImpresoraNavegador);

        Livewire::test(Ajustes::class)
            ->set('impresora', 'X')->set('ticketAutomatico', true)->call('guardar')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'solo está disponible en la app de escritorio'))
            ->assertSee('diálogo de impresión');
    }
}

class ImpresoraFalsa implements ImpresoraTickets
{
    /** @var array<int, array{html: string, impresora: string}> */
    public array $impresos = [];

    public ?string $falla = null;

    public function puedeImprimirDirecto(): bool
    {
        return true;
    }

    public function impresoras(): array
    {
        return ['EPSON TM-T20', 'Microsoft Print to PDF'];
    }

    public function imprimir(string $html, string $impresora): ?string
    {
        if ($this->falla !== null) {
            return $this->falla;
        }

        $this->impresos[] = ['html' => $html, 'impresora' => $impresora];

        return null;
    }
}
