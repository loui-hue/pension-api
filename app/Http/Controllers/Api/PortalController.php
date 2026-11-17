<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PortalSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

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

    /**
     * Solo en el servidor de la pensión. El comensal inició sesión fuera (en el
     * portal) y luego entró al Wi-Fi de la pensión: la pensión no conoce ese token.
     * Se lo pregunta al portal y, si es válido y es de un comensal, lo acepta. Así
     * la app no lo saca ni le pide otra vez la contraseña al cambiar de red.
     */
    public function traerSesion(Request $request, PortalSync $portal)
    {
        $plano = (string) $request->bearerToken();
        $url = rtrim((string) config('portal.sync_url'), '/');
        if (config('portal.modo') !== 'local' || $url === '') {
            return response()->json(['message' => 'No disponible'], 404);
        }
        if (!str_contains($plano, '|')) {
            return response()->json(['message' => 'Sesión inválida'], 401);
        }
        if (PersonalAccessToken::findToken($plano)) {
            return response()->json(['message' => 'Sesión válida']);
        }

        try {
            $respuesta = Http::withToken($plano)->acceptJson()->timeout(8)->get("{$url}/api/me");
        } catch (Throwable) {
            return response()->json(['message' => 'Sin conexión con el portal'], 503);
        }

        $user = $respuesta->successful() ? User::find($respuesta->json('id')) : null;
        if (!$user || !$user->hasRole('cliente') || $user->email !== $respuesta->json('email')) {
            return response()->json(['message' => 'Sesión inválida'], 401);
        }

        $portal->aceptarTokenDelPortal($user, $plano);

        return response()->json(['message' => 'Sesión válida']);
    }
}
