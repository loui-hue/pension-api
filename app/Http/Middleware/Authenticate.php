<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Es solo un API: no existe página de login a la cual redirigir.
        // Sin sesión se responde 401 en vez de un error 500 "Route [login] not defined".
        return null;
    }
}
