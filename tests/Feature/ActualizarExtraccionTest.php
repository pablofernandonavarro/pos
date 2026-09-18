<?php

namespace Tests\Feature;

use App\Console\Commands\ActualizarCommand;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El paquete de actualización se descomprime con la herramienta del sistema operativo
 * (PowerShell en Windows, unzip en Mac). Corre de verdad en el sistema donde se ejecuta la
 * suite: en CI pasa en el runner de Windows y en el de Mac.
 */
class ActualizarExtraccionTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = storage_path('framework/testing/extraccion-'.uniqid());
        File::ensureDirectoryExists($this->carpeta);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);

        parent::tearDown();
    }

    public function test_extrae_el_paquete_con_la_herramienta_del_sistema(): void
    {
        $zip = "{$this->carpeta}/paquete.zip";
        $destino = "{$this->carpeta}/staging con espacios";

        $archivo = new \ZipArchive;
        $archivo->open($zip, \ZipArchive::CREATE);
        $archivo->addFromString('VERSION', "9.9.9\n");
        $archivo->addFromString('app/Nuevo.php', '<?php // nuevo');
        $archivo->close();

        File::ensureDirectoryExists($destino);
        $proceso = ActualizarCommand::procesoDeExtraccion($zip, $destino);
        $proceso->setTimeout(120);
        $proceso->run();

        $this->assertTrue($proceso->isSuccessful(), $proceso->getErrorOutput());
        $this->assertSame('9.9.9', trim(File::get("{$destino}/VERSION")));
        $this->assertFileExists("{$destino}/app/Nuevo.php");
    }

    public function test_fuera_de_windows_usa_unzip(): void
    {
        $linea = ActualizarCommand::procesoDeExtraccion('/tmp/a.zip', '/tmp/b')->getCommandLine();

        PHP_OS_FAMILY === 'Windows'
            ? $this->assertStringContainsString('Expand-Archive', $linea)
            : $this->assertStringContainsString('/usr/bin/unzip', $linea);
    }
}
