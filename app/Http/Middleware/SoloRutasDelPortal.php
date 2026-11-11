<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En modo portal (nube) solo existen las rutas de consulta del comensal;
 * todo lo que opera la pensión (pedidos, cobros, menú, usuarios...) responde 404.
 */
class SoloRutasDelPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('portal.modo') === 'portal' && !$request->is(config('portal.rutas_portal'))) {
            return response()->json(['message' => 'Disponible solo en la red de la pensión'], 404);
        }

        return $next($request);
    }
}
