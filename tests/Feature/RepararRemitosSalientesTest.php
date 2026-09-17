<?php

namespace Tests\Feature;

use App\Models\RemitoSaliente;
use App\Services\RemitosSalientesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reproduce el bug real de Caja1: una caja que corrió v1.8.7 queda con remitos_salientes
 * en el esquema viejo porque v1.8.8 reescribió el archivo de la migración en vez de
 * agregar una nueva, y migrate --force no vuelve a correr una migración ya aplicada.
 */
class RepararRemitosSalientesTest extends TestCase
{
    use RefreshDatabase;

    private const ARCHIVO_MIGRACION = 'database/migrations/2026_09_18_000001_reparar_esquema_viejo_de_remitos_salientes.php';

    /** Recrea remitos_salientes con el esquema exacto que publicó v1.8.7. */
    private function simularEsquemaViejoV187(): void
    {
        Schema::dropIfExists('remitos_salientes');

        Schema::create('remitos_salientes', function (Blueprint $table) {
            $table->id();
            $table->string('numero');
            $table->unsignedBigInteger('destino_sucursal_id');
            $table->string('destino_nombre');
            $table->json('items');
            $table->unsignedInteger('total_unidades')->default(0);
            $table->text('observaciones')->nullable();
            $table->dateTime('enviado_at');
            $table->timestamps();
        });
    }

    /** Carga la migración de reparación como clase, sin pasar por la tabla `migrations`. */
    private function migracionDeReparacion(): Migration
    {
        return require base_path(self::ARCHIVO_MIGRACION);
    }

    public function test_repara_una_tabla_que_quedo_con_el_esquema_viejo_de_v187(): void
    {
        $this->simularEsquemaViejoV187();
        $this->assertFalse(Schema::hasColumn('remitos_salientes', 'estado'));

        $this->migracionDeReparacion()->up();

        $this->assertTrue(Schema::hasColumn('remitos_salientes', 'estado'));
        $this->assertTrue(Schema::hasColumn('remitos_salientes', 'confirmado_at'));
    }

    public function test_recrea_la_tabla_si_no_existe(): void
    {
        Schema::dropIfExists('remitos_salientes');

        $this->migracionDeReparacion()->up();

        $this->assertTrue(Schema::hasColumn('remitos_salientes', 'estado'));
        $this->assertTrue(Schema::hasColumn('remitos_salientes', 'confirmado_at'));
    }

    public function test_no_hace_nada_si_el_esquema_ya_esta_correcto(): void
    {
        $this->migracionDeReparacion()->up();

        $this->assertTrue(Schema::hasColumn('remitos_salientes', 'estado'));
    }

    public function test_sincronizar_funciona_tras_reparar_una_tabla_con_esquema_viejo(): void
    {
        $this->configurarCaja();
        $this->simularEsquemaViejoV187();

        Http::fake(['*/pos/remitos/enviados*' => Http::response(['data' => [
            [
                'id' => 900, 'numero' => 'R-001', 'destino_sucursal_id' => 2, 'destino' => 'Central',
                'estado' => 'remitido', 'observaciones' => null,
                'items' => [['product_id' => 500, 'nombre' => 'Zapatillas', 'codigo' => 'ZAP1', 'cantidad' => 4]],
                'remitido_at' => now()->toIso8601String(), 'confirmado_at' => null,
            ],
        ]])]);

        $this->migracionDeReparacion()->up();

        $resultado = app(RemitosSalientesService::class)->sincronizar();

        $this->assertTrue($resultado['success']);

        $remito = RemitoSaliente::find(900);
        $this->assertNotNull($remito, 'sincronizar() debería poder guardar contra la tabla reparada');
        $this->assertSame('remitido', $remito->estado);
        $this->assertSame(4, $remito->total_unidades);
    }
}
