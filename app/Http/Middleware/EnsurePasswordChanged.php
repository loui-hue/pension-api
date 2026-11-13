<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras el usuario tenga una contraseña temporal solo puede cambiarla,
 * consultar su perfil o cerrar sesión; el resto del API responde 403.
 */
class EnsurePasswordChanged
{
    private const PERMITIDAS = ['api/me', 'api/me/password', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && !$request->is(self::PERMITIDAS)) {
            return response()->json([
                'message' => 'Debes crear tu propia contraseña antes de continuar',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
