<?php

namespace App\Providers;

use App\Contracts\CajonDinero;
use App\Contracts\ImpresoraTickets;
use App\Services\Impresion\CajonDineroWindows;
use App\Services\Impresion\ImpresoraNativePHP;
use App\Services\Impresion\ImpresoraNavegador;
use App\Support\VersionPos;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La impresión directa solo existe dentro de la app de escritorio.
        $this->app->bind(ImpresoraTickets::class, fn () => VersionPos::tipo() === 'escritorio'
            ? new ImpresoraNativePHP
            : new ImpresoraNavegador);

        // El cajón va por la cola de Windows: funciona igual en la clásica y en escritorio.
        $this->app->bind(CajonDinero::class, CajonDineroWindows::class);
    }
}
