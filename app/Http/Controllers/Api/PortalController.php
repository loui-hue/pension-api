<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortalSync;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    /**
     * Público. La app lo usa para saber si está hablando con el servidor de la
     * pensión ("local") o con el portal en la nube, y desde cuándo hay datos.
     */
    public function ping()
    {
        return response()->json([
            'modo' => config('portal.modo'),
            'sincronizado_en' => config('portal.modo') === 'portal' ? cache('portal.sincronizado_en') : null,
        ]);
    }

    /** Solo en el portal: recibe la copia enviada por el servidor de la pensión. */
    public function sync(Request $request, PortalSync $portal)
    {
        $token = (string) config('portal.sync_token');
        if (config('portal.modo') !== 'portal') {
            return response()->json(['message' => 'No disponible'], 404);
        }
        if ($token === '' || !hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Token de sincronización inválido'], 401);
        }

        $request->validate(['tablas' => 'required|array']);

        return response()->json([
            'message' => 'Sincronizado',
            'filas' => $portal->aplicar($request->all()),
        ]);
    }
}
