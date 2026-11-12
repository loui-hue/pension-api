<?php

namespace App\Http\Middleware;

use App\Services\PortalSync;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * En el servidor de la pensión: después de cualquier cambio que salió bien
 * (cobro, pedido, reto, registro de comensal...), envía la copia al portal en
 * segundo plano. Así el comensal ve su avance en la nube a los pocos segundos,
 * sin botones ni esperas. Varios cambios seguidos se agrupan en un solo envío.
 */
class SincronizarPortalTrasCambios
{
    // Peticiones que no cambian datos de comensales (POST /loyalty/streak solo consulta la racha).
    private const NO_CUENTAN = ['api/login', 'api/logout', 'api/ping', 'api/portal/sync', 'api/loyalty/streak'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $esCambio = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($esCambio && $response->isSuccessful() && !$request->is(self::NO_CUENTAN)
            && Cache::add('portal.sync.programado', true, 5)) {
            PortalSync::programar();
        }

        return $response;
    }
}
