<?php

namespace Tests\Feature;

use App\Contracts\CajonDinero;
use App\Exceptions\CajaException;
use App\Livewire\Pos\Ajustes;
use App\Livewire\Pos\Caja as PantallaCaja;
use App\Livewire\Pos\Venta as PantallaVenta;
use App\Models\AperturaCajon;
use App\Models\Configuracion;
use App\Services\CajaService;
use App\Services\CajonService;
use App\Services\Impresion\CajonDineroMac;
use App\Services\Impresion\CajonDineroWindows;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CajonDineroTest extends TestCase
{
    use RefreshDatabase;

    private CajonFalso $cajon;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurarCaja();
        $this->cajon = new CajonFalso;
        $this->app->instance(CajonDinero::class, $this->cajon);
        Configuracion::set('impresora_ticket', 'EPSON TM-T20');
        Configuracion::set('cajon_habilitado', '1');
        app(CajaService::class)->abrir('Ana', 0);
    }

    public function test_abre_solo_con_efectivo_y_si_esta_habilitado(): void
    {
        $servicio = app(CajonService::class);

        $this->assertNull($servicio->abrirPorEfectivo(false));
        $this->assertSame([], $this->cajon->aperturas, 'sin efectivo no abre');

        $this->assertNull($servicio->abrirPorEfectivo(true));
        $this->assertSame(['EPSON TM-T20'], $this->cajon->aperturas);

        Configuracion::set('cajon_automatico', '0');
        $servicio->abrirPorEfectivo(true);
        Configuracion::set('cajon_automatico', '1');
        Configuracion::set('cajon_habilitado', '0');
        $servicio->abrirPorEfectivo(true);

        $this->assertCount(1, $this->cajon->aperturas);
    }

    public function test_venta_en_efectivo_abre_el_cajon_y_con_tarjeta_no(): void
    {
        $p = $this->producto(['precio' => 1000, 'stock' => 5]);

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)->call('abrirCobro')
            ->call('elegirMedio', 'debito')->set('pagoMonto', '1000')
            ->call('finalizarVenta')->assertSet('error', null);

        $this->assertSame([], $this->cajon->aperturas);

        $this->cajon->falla = 'Sin conexión con la impresora';

        Livewire::test(PantallaVenta::class)
            ->call('agregarAlCarrito', $p->id)->call('abrirCobro')
            ->call('finalizarVenta')
            ->assertSet('error', null)
            ->assertSet('aviso', fn ($a) => str_contains((string) $a, 'Sin conexión con la impresora'));

        $this->assertCount(1, $this->cajon->aperturas, 'la venta queda registrada aunque el cajón falle');
    }

    public function test_apertura_manual_pide_motivo_y_queda_en_el_informe(): void
    {
        $turno = app(CajaService::class)->turnoAbierto();

        try {
            app(CajonService::class)->abrirManual($turno, '  ');
            $this->fail('Tenía que pedir motivo');
        } catch (CajaException $e) {
            $this->assertStringContainsString('por qué', $e->getMessage());
        }

        Livewire::test(PantallaCaja::class)
            ->set('motivoCajon', 'Dar cambio')
            ->call('abrirCajon')
            ->assertSet('exito', fn ($m) => str_contains((string) $m, 'Cajón abierto'));

        $this->cajon->falla = 'Apagada';
        Livewire::test(PantallaCaja::class)->set('motivoCajon', 'Revisar')->call('abrirCajon')->assertSet('error', 'Apagada');

        $this->assertSame([true, false], AperturaCajon::orderBy('id')->pluck('abrio')->all());

        $html = app(TicketService::class)->htmlInforme($turno);
        $this->assertStringContainsString('CAJÓN ABIERTO SIN VENTA (2)', $html);
        $this->assertStringContainsString('Revisar (no abrió)', $html);
    }

    public function test_ajustes_guarda_el_cajon_y_prueba_la_apertura(): void
    {
        Configuracion::set('cajon_habilitado', '0');

        Livewire::test(Ajustes::class)
            ->set('cajonHabilitado', true)
            ->set('cajonAutomatico', false)
            ->call('guardar')->assertSet('error', null)
            ->call('probarCajon')->assertSet('mensaje', fn ($m) => str_contains((string) $m, 'EPSON TM-T20'));

        $this->assertSame(['1', '0'], [Configuracion::get('cajon_habilitado'), Configuracion::get('cajon_automatico')]);
        $this->assertSame(['EPSON TM-T20'], $this->cajon->aperturas);
    }

    public function test_el_script_manda_el_pulso_a_la_impresora_con_el_nombre_escapado(): void
    {
        $script = CajonDineroWindows::script("Caja O'Brien");

        $this->assertStringContainsString("[PosCajon]::Enviar('Caja O''Brien', [byte[]]@(0x1b,0x70,0x0,0x19,0xfa))", $script);
        $this->assertStringContainsString('Tipo = "RAW"', $script);
        $this->assertStringStartsWith("\$ErrorActionPreference = 'Stop'", $script);
        $this->assertMatchesRegularExpression("/^'@$/m", $script, 'el here-string de PowerShell cierra en la columna 0');
        $this->assertSame($script, mb_convert_encoding(base64_decode(CajonDineroWindows::comandoCodificado("Caja O'Brien")), 'UTF-8', 'UTF-16LE'));
    }

    public function test_en_mac_manda_el_mismo_pulso_en_crudo_por_cups(): void
    {
        $this->assertSame(['/usr/bin/lp', '-d', "Caja O'Brien", '-o', 'raw'], CajonDineroMac::comando("Caja O'Brien"));
        $this->assertSame("\x1B\x70\x00\x19\xFA", CajonDineroMac::pulso());
        $this->assertSame(PHP_OS_FAMILY === 'Darwin', (new CajonDineroMac)->disponible());
    }

    public function test_el_binding_elige_el_cajon_segun_el_sistema(): void
    {
        $this->app->forgetInstance(CajonDinero::class);
        (new \App\Providers\AppServiceProvider($this->app))->register();

        $esperado = PHP_OS_FAMILY === 'Darwin' ? CajonDineroMac::class : CajonDineroWindows::class;
        $this->assertInstanceOf($esperado, app(CajonDinero::class));
    }

    public function test_en_mac_una_impresora_inexistente_da_un_error_legible(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Solo Mac');
        }

        $error = (new CajonDineroMac)->abrir('Impresora que no existe '.uniqid());

        $this->assertNotNull($error);
        $this->assertStringContainsString('No se pudo abrir el cajón', $error);
    }

    public function test_en_windows_una_impresora_inexistente_da_un_error_legible(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Solo Windows');
        }

        $error = (new CajonDineroWindows)->abrir('Impresora que no existe '.uniqid());

        $this->assertNotNull($error);
        $this->assertStringContainsString('No se pudo abrir el cajón', $error);
    }
}

class CajonFalso implements CajonDinero
{
    /** @var array<int, string> */
    public array $aperturas = [];

    public ?string $falla = null;

    public function disponible(): bool
    {
        return true;
    }

    public function abrir(string $impresora): ?string
    {
        $this->aperturas[] = $impresora;

        return $this->falla;
    }
}
