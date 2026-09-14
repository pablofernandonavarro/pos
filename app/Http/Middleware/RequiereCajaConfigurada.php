<?php

namespace App\Http\Middleware;

use App\Models\Configuracion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una caja sin instalar no tiene catálogo ni token: la pantalla de venta abriría vacía y
 * nada sincronizaría. En la app de escritorio lo primero que ve el usuario tiene que
 * ser la pantalla para pegar el código de instalación.
 */
class RequiereCajaConfigurada
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Configuracion::isConfigured()) {
            return redirect()->route('pos.configuracion');
        }

        return $next($request);
    }
}
