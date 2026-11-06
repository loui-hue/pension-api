<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permisos por rol: `role:mesero,caja` deja pasar solo a esos roles.
 * El administrador siempre tiene acceso.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $permitidos = array_merge($roles, ['administrador']);

        if (!$user || !$user->roles->pluck('name')->intersect($permitidos)->isNotEmpty()) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción'], 403);
        }

        return $next($request);
    }
}
