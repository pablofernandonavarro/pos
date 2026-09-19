<?php

namespace App\Console\Commands;

use App\Support\VersionPos;
use Illuminate\Console\Command;

/**
 * Reemplaza al `optimize` de Laravel.
 *
 * La app de escritorio corre `artisan optimize` cada vez que arranca una versión nueva,
 * con las variables de ese arranque: el secreto que Electron exige en cada pedido y el
 * puerto de su API. `config:cache` las congela en bootstrap/cache/config.php, y en el
 * arranque siguiente PHP usa el secreto y el puerto viejos: Electron rechaza los
 * pedidos y la caja queda abierta pero sin funcionar (pasa al reiniciar la PC).
 *
 * En escritorio solo se cachean vistas (compiladas en storage_path(), fuera del .app) y
 * se borra cualquier config cacheada que haya quedado. `route:cache` y `event:cache`
 * escriben en bootstrap_path('cache/...'), que en la app empaquetada queda DENTRO del
 * .app firmado (Contents/Resources/build/app/bootstrap/cache/): al arrancar reescriben
 * un recurso sellado por la firma ad-hoc y macOS pasa a tratar la app como dañada. En la
 * instalación clásica hace lo mismo que el optimize original.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'optimize';

    protected $description = 'Cachea lo que es seguro cachear (en escritorio, sin la configuración ni rutas/eventos)';

    public function handle(): int
    {
        $escritorio = VersionPos::tipo() === 'escritorio';

        $pasos = $escritorio
            ? ['config:clear', 'view:cache']
            : ['config:cache', 'event:cache', 'route:cache', 'view:cache'];

        foreach ($pasos as $paso) {
            if ($this->call($paso) !== self::SUCCESS) {
                $this->error("Falló {$paso}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
