<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Services\ProvisionService;
use Illuminate\Console\Command;

class ProvisionCommand extends Command
{
    protected $signature = 'pos:provision {codigo? : Código de instalación generado en el Manager} {--url= : URL del Manager (por defecto la de MANAGER_API_URL)}';

    protected $description = 'Configura esta caja canjeando un código de instalación del Manager';

    public function handle(ProvisionService $provision): int
    {
        // La URL puede venir de tres lados. Se prueba la ya configurada antes que el .env
        // porque env() devuelve null si alguien corrió config:cache.
        $url = $this->option('url')
            ?: Configuracion::get('manager_api_url')
            ?: config('pos.manager_api_url', '');

        if (! $url) {
            $url = (string) $this->ask('URL del Manager (ej: http://manager.test)');
        }

        if (! $url) {
            $this->error('Hace falta la URL del Manager. Definí MANAGER_API_URL en .env o pasá --url=');

            return self::FAILURE;
        }

        $codigo = $this->argument('codigo') ?: $this->ask('Código de instalación (te lo da el Manager)');

        if (! $codigo) {
            $this->error('Hace falta un código de instalación.');

            return self::FAILURE;
        }

        $this->info('Canjeando el código contra '.ProvisionService::normalizarUrl($url).'...');

        $resultado = $provision->instalar($url, $codigo);

        if (! $resultado['success']) {
            $this->error($resultado['error']);

            return self::FAILURE;
        }

        $this->line('');
        $this->info('✅ Caja configurada');
        $this->line("   Punto de venta: {$resultado['pdv_nombre']} (ID {$resultado['punto_de_venta_id']})");
        $this->line("   Sucursal:       {$resultado['sucursal_nombre']}");
        $this->line('');

        $sync = $resultado['sync'];

        if (! $sync['success']) {
            $this->warn('⚠ La caja quedó configurada pero falló la sincronización inicial:');
            $this->warn('  '.($sync['error'] ?? 'error desconocido'));
            $this->line('  Reintentá con: php artisan pos:sync --pull');

            return self::SUCCESS;
        }

        $r = $sync['resultados'];
        $this->line("   Productos: {$r['productos']['cantidad']}");
        $this->line("   Precios:   {$r['precios']['cantidad']}");
        $this->line("   Stock:     {$r['stock']['cantidad']}");
        $this->line('');
        $this->info('🎉 Caja lista para vender.');

        return self::SUCCESS;
    }
}
