<?php

namespace App\Console\Commands;

use App\Services\ManagerApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ActualizarCommand extends Command
{
    protected $signature = 'pos:actualizar {--forzar : Reinstala aunque ya tenga la versión vigente}';

    protected $description = 'Descarga del Manager la última versión del POS y la aplica';

    /**
     * Lo que jamás se pisa: es de esta instalación, no del código.
     *
     * @var array<int, string>
     */
    private const INTOCABLE = [
        '.env', '.pos-info', 'database/database.sqlite', 'storage', 'vendor', 'node_modules',
    ];

    public function handle(ManagerApiService $managerApi): int
    {
        $info = $managerApi->obtenerVersion();

        if (! $info['success']) {
            $this->error($info['error']);

            return self::FAILURE;
        }

        $remota = $info['data']['version'];
        $local = $this->versionLocal();

        $this->line('Versión instalada: '.($local ?? 'desconocida'));
        $this->line("Versión publicada: {$remota}");

        if ($local === $remota && ! $this->option('forzar')) {
            $this->info('✅ Ya está al día.');

            return self::SUCCESS;
        }

        $zip = storage_path("app/actualizacion-{$remota}.zip");
        $staging = storage_path("app/actualizacion-{$remota}");
        $respaldo = storage_path('app/respaldo-'.now()->format('YmdHis'));

        $this->info('Descargando paquete...');

        $descarga = $managerApi->descargarPaquete($zip);

        if (! $descarga['success']) {
            $this->error($descarga['error']);

            return self::FAILURE;
        }

        // Antes de tocar un solo archivo: el paquete tiene que ser exactamente el que el
        // Manager dice haber publicado.
        if (! hash_equals($info['data']['hash'], hash_file('sha256', $zip))) {
            File::delete($zip);
            $this->error('El paquete no coincide con el hash publicado. Se aborta sin tocar nada.');

            return self::FAILURE;
        }

        $this->info('✅ Integridad verificada');

        try {
            $this->info('Extrayendo...');
            $this->extraer($zip, $staging);

            // El paquete se valida en el staging, antes de que la instalación corra riesgo.
            if (trim(File::get("{$staging}/VERSION")) !== $remota) {
                throw new \RuntimeException('El paquete no contiene la versión esperada');
            }

            $archivos = $this->archivosAAplicar($staging);

            $this->info('Respaldando '.count($archivos).' archivo(s)...');
            $this->respaldar($archivos, $respaldo);

            $this->info('Aplicando...');
            foreach ($archivos as $relativa) {
                $destino = base_path($relativa);
                File::ensureDirectoryExists(dirname($destino));
                File::copy("{$staging}/{$relativa}", $destino);
            }

            $this->info('Migrando base de datos...');
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');

            File::delete($zip);
            File::deleteDirectory($staging);
            $this->rotarRespaldos();

            $this->info("🎉 Actualizado a {$remota}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Falló: '.$e->getMessage());
            $this->warn('Restaurando la versión anterior...');

            $this->restaurar($respaldo);
            Artisan::call('optimize:clear');

            File::delete($zip);
            File::deleteDirectory($staging);

            $this->warn('Se volvió a la versión anterior.');

            return self::FAILURE;
        }
    }

    /**
     * Deja solo los últimos respaldos. Esto corre desatendido para siempre: sin rotar,
     * cada actualización suma ~700 KB en el disco de la caja y nadie los borra nunca.
     */
    private function rotarRespaldos(int $conservar = 3): void
    {
        $respaldos = glob(storage_path('app/respaldo-*')) ?: [];

        sort($respaldos);

        foreach (array_slice($respaldos, 0, max(0, count($respaldos) - $conservar)) as $viejo) {
            File::deleteDirectory($viejo);
        }
    }

    private function versionLocal(): ?string
    {
        $archivo = base_path('VERSION');

        return file_exists($archivo) ? trim(File::get($archivo)) : null;
    }

    /**
     * Se extrae con PowerShell y no con ZipArchive porque el PHP de las cajas (XAMPP)
     * viene sin la extensión zip cargada. Depender de ella obligaría a editar el php.ini
     * de cada máquina, que es justo el trabajo manual que este comando viene a evitar.
     */
    private function extraer(string $zip, string $destino): void
    {
        File::deleteDirectory($destino);
        File::ensureDirectoryExists($destino);

        // Las rutas van por variables de entorno y no interpoladas en el comando: no hay
        // comillas que escapar y no se arma una cadena que PowerShell tenga que parsear.
        // ($args no se llena cuando se usa -Command, solo con -File.)
        $proceso = new Process([
            'powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-Command', 'Expand-Archive -LiteralPath $env:POS_ZIP -DestinationPath $env:POS_DEST -Force',
        ]);

        $proceso->setEnv(['POS_ZIP' => $zip, 'POS_DEST' => $destino]);
        $proceso->setTimeout(300);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            throw new \RuntimeException('No se pudo extraer el paquete: '.trim($proceso->getErrorOutput()));
        }

        // Expand-Archive devuelve código 0 aunque falle (error no terminante), así que el
        // exit code no alcanza: lo que confirma que salió bien es que el VERSION esté.
        if (! file_exists("{$destino}/VERSION")) {
            $detalle = trim($proceso->getErrorOutput());

            throw new \RuntimeException('La extracción no produjo archivos'.($detalle ? ': '.mb_substr($detalle, 0, 300) : ''));
        }
    }

    /**
     * @return array<int, string>
     */
    private function archivosAAplicar(string $staging): array
    {
        $rutas = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterador as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $relativa = str_replace('\\', '/', substr($item->getPathname(), strlen($staging) + 1));

            if (! $this->esIntocable($relativa)) {
                $rutas[] = $relativa;
            }
        }

        return $rutas;
    }

    private function esIntocable(string $ruta): bool
    {
        foreach (self::INTOCABLE as $protegido) {
            if ($ruta === $protegido || str_starts_with($ruta, $protegido.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $archivos
     */
    private function respaldar(array $archivos, string $destino): void
    {
        File::ensureDirectoryExists($destino);

        foreach ($archivos as $relativa) {
            $actual = base_path($relativa);

            // Un archivo nuevo no tiene qué respaldar; se anota para poder borrarlo si
            // hay que volver atrás.
            if (! file_exists($actual)) {
                File::append("{$destino}/_archivos-nuevos.txt", $relativa.PHP_EOL);

                continue;
            }

            $copia = "{$destino}/{$relativa}";
            File::ensureDirectoryExists(dirname($copia));
            File::copy($actual, $copia);
        }
    }

    private function restaurar(string $respaldo): void
    {
        if (! is_dir($respaldo)) {
            return;
        }

        $listaNuevos = "{$respaldo}/_archivos-nuevos.txt";

        if (file_exists($listaNuevos)) {
            foreach (file($listaNuevos, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $relativa) {
                File::delete(base_path($relativa));
            }
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($respaldo, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterador as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $relativa = str_replace('\\', '/', substr($item->getPathname(), strlen($respaldo) + 1));

            if ($relativa === '_archivos-nuevos.txt') {
                continue;
            }

            $destino = base_path($relativa);
            File::ensureDirectoryExists(dirname($destino));
            File::copy($item->getPathname(), $destino);
        }
    }
}
