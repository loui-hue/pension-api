<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En modo portal (nube) solo existen las rutas de consulta del comensal y la
 * consulta de la carta (solo GET); todo lo que opera la pensión (pedidos, cobros,
 * cambios en el menú, usuarios...) responde 404.
 */
class SoloRutasDelPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('portal.modo') !== 'portal') {
            return $next($request);
        }

        $permitida = $request->is(config('portal.rutas_portal'))
            || ($request->isMethod('GET') && $request->is(config('portal.rutas_portal_solo_consulta')));

        if (!$permitida) {
            return response()->json(['message' => 'Disponible solo en la red de la pensión'], 404);
        }

        return $next($request);
    }
}
