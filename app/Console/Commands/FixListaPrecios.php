<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixListaPrecios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:lista-precios {factor=1.8}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza el factor de todas las listas default a un valor específico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $factor = $this->argument('factor');

        $this->info("Actualizando listas de precios con factor: {$factor}");

        // Actualizar todas las listas que están marcadas como default
        $updated = \App\Models\ListaPrecio::where('es_default', true)->update([
            'factor' => $factor
        ]);

        $this->info("Se actualizaron {$updated} lista(s) de precios.");

        // Mostrar el estado actual
        $this->newLine();
        $this->info('Estado actual de las listas:');
        $listas = \App\Models\ListaPrecio::all();
        foreach ($listas as $lista) {
            $this->line("ID: {$lista->id} | Nombre: {$lista->nombre} | Factor: {$lista->factor} | Default: " . ($lista->es_default ? 'SÍ' : 'NO'));
        }

        // Verificar precio del producto
        $this->newLine();
        $producto = \App\Models\Producto::where('codigo_interno', 'ZAP001')->first();
        if ($producto) {
            $this->info("Precio de ZAP001:");
            $this->line("Base: \${$producto->precio}");
            $this->line("Efectivo: \$" . number_format($producto->getPrecioEfectivo(), 0, ',', '.'));
        }
    }
}
