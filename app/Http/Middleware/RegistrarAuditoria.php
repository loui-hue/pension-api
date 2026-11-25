<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro de auditoría: después de cada acción que cambia datos, guarda quién la
 * hizo, cuándo y qué fue (inicios de sesión, pedidos, cobros, gestión del
 * administrador, fidelización manual).
 *
 * - Se ejecuta DESPUÉS de la acción, ya confirmada en la base: no interfiere con
 *   los pedidos ni con los cobros (que usan transacciones y bloqueos).
 * - Si guardar el registro fallara, la acción del usuario no se ve afectada: el
 *   error solo queda en el log del servidor.
 * - Solo en la pensión. El portal en la nube es de solo consulta y no registra.
 * - Nunca guarda contraseñas ni tokens.
 */
class RegistrarAuditoria
{
    private const ESTADOS = [
        'pendiente' => 'pendiente',
        'en_preparacion' => 'en preparación',
        'listo' => 'listo',
        'entregado' => 'entregado',
        'cancelado' => 'cancelado',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('portal.modo') === 'portal' || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        try {
            $registro = $this->describir($request, $response);
            if ($registro !== null) {
                $this->guardar($request, $response, $registro);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el registro de auditoría: ' . $e->getMessage());
        }

        return $response;
    }

    /** @return array{0:string,1:string,2:?string,3:?int,4:?User}|null  [acción, descripción, tipo, id, usuario] */
    private function describir(Request $request, Response $response): ?array
    {
        $accion = $request->route()?->getActionName();
        if (!$accion || !str_contains($accion, '@')) {
            return null;
        }
        [$clase, $metodo] = explode('@', class_basename($accion));
        $estado = $response->getStatusCode();
        $d = $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        $id = $this->idDeRuta($request);

        // Inicio de sesión: también se registran los intentos fallidos y bloqueados.
        if ($clase === 'AuthController' && $metodo === 'login') {
            $cuenta = mb_strtolower(trim((string) $request->input('email')));
            if ($estado === 200) {
                return ['sesion.inicio', 'Inició sesión', 'usuario', $d['user']['id'] ?? null, User::find($d['user']['id'] ?? 0)];
            }
            $usuario = User::where(str_contains($cuenta, '@') ? 'email' : 'phone', $cuenta)->first();
            if ($estado === 401) {
                return ['sesion.fallida', "Intento fallido de inicio de sesión con «{$cuenta}»", 'usuario', $usuario?->id, $usuario];
            }
            return null;   // los frenados (429) los registra inicioFrenado(), desde el limitador
        }

        if ($estado < 200 || $estado >= 300) {
            return null;   // solo las acciones que se realizaron
        }

        $bs = fn ($monto) => 'Bs. ' . number_format((float) $monto, 2, '.', '');

        return match ("{$clase}@{$metodo}") {
            'AuthController@logout' => ['sesion.cierre', 'Cerró sesión', null, null, null],
            'AuthController@changePassword' => ['sesion.contrasena', 'Cambió su contraseña (se cerraron sus otras sesiones)', null, null, null],
            'PortalController@traerSesion' => ['sesion.portal', 'Entró a la pensión con la sesión iniciada en el portal', null, null, null],

            'UserController@storeCustomer' => ['usuario.registro', "Registró al comensal {$d['name']} (celular {$d['phone']})", 'usuario', $d['id'] ?? null, null],
            'UserController@resetCustomerPassword' => ['usuario.contrasena', 'Restableció la contraseña del comensal ' . (User::find($id)?->name ?? "#{$id}"), 'usuario', $id, null],

            'OrderController@store' => ['pedido.creado', "Registró el pedido #{$d['id']} de " . ($d['user']['name'] ?? 'un comensal')
                . ', mesa ' . ($d['table']['number'] ?? '?') . ', ' . $bs($d['total'] ?? 0), 'pedido', $d['id'] ?? null, null],
            'OrderController@update' => ['pedido.modificado', "Modificó el pedido #{$d['id']} (" . $bs($d['total'] ?? 0) . ')', 'pedido', $d['id'] ?? null, null],
            'OrderController@updateStatus' => ($d['status'] ?? '') === 'cancelado'
                ? ['pedido.cancelado', "Canceló el pedido #{$d['id']}", 'pedido', $d['id'] ?? null, null]
                : ['pedido.estado', "Pasó el pedido #{$d['id']} a «" . (self::ESTADOS[$d['status'] ?? ''] ?? ($d['status'] ?? '?')) . '»', 'pedido', $d['id'] ?? null, null],
            'OrderController@destroy' => ['pedido.eliminado', "Eliminó el pedido #{$id}", 'pedido', $id, null],

            'PaymentController@store' => ($d['status'] ?? '') === 'completado'
                ? ['cobro.registrado', "Cobró el pedido #{$d['order_id']}: " . $bs($d['amount'] ?? 0) . ' en ' . ($d['payment_method']['name'] ?? 'efectivo'), 'pago', $d['id'] ?? null, null]
                : ['cobro.pendiente', "Registró un pago por " . ($d['payment_method']['name'] ?? 'QR') . " del pedido #{$d['order_id']} (" . $bs($d['amount'] ?? 0) . ', por confirmar)', 'pago', $d['id'] ?? null, null],
            'PaymentController@confirm' => ['cobro.confirmado', "Confirmó el pago del pedido #{$d['order_id']} (" . $bs($d['amount'] ?? 0) . ')', 'pago', $d['id'] ?? null, null],
            'PaymentController@reject' => ['cobro.rechazado', "Rechazó el pago del pedido #{$d['order_id']} (" . $bs($d['amount'] ?? 0) . ')', 'pago', $d['id'] ?? null, null],
            'PaymentController@update' => ['cobro.editado', "Editó el pago #{$id} del pedido #" . ($d['order_id'] ?? '?'), 'pago', $id, null],
            'PaymentController@destroy' => ['cobro.eliminado', "Eliminó el pago #{$id}", 'pago', $id, null],
            'PaymentController@updateMethod' => ['gestion.metodo_pago', "Actualizó el método de pago «{$d['name']}»", 'metodo_pago', $d['id'] ?? null, null],

            'ProductController@store' => ['gestion.producto', "Creó el producto «{$d['name']}» (" . $bs($d['price'] ?? 0) . ')', 'producto', $d['id'] ?? null, null],
            'ProductController@update' => array_keys($request->all()) === ['available']
                ? ['gestion.producto', ($d['available'] ?? false) ? "Marcó «{$d['name']}» como disponible" : "Marcó «{$d['name']}» como no disponible", 'producto', $d['id'] ?? null, null]
                : ['gestion.producto', "Editó el producto «{$d['name']}» (" . $bs($d['price'] ?? 0) . ')', 'producto', $d['id'] ?? null, null],
            'ProductController@destroy' => ['gestion.producto', $d['message'] ?? "Eliminó el producto #{$id}", 'producto', $id, null],

            'CategoryController@store' => ['gestion.categoria', "Creó la categoría «{$d['name']}»", 'categoria', $d['id'] ?? null, null],
            'CategoryController@update' => ['gestion.categoria', "Renombró la categoría a «{$d['name']}»", 'categoria', $d['id'] ?? null, null],
            'CategoryController@destroy' => ['gestion.categoria', $d['message'] ?? "Eliminó la categoría #{$id}", 'categoria', $id, null],

            'TableController@store' => ['gestion.mesa', "Agregó la mesa {$d['number']} ({$d['capacity']} personas)", 'mesa', $d['id'] ?? null, null],
            'TableController@update' => ['gestion.mesa', "Editó la mesa {$d['number']} ({$d['capacity']} personas)", 'mesa', $d['id'] ?? null, null],
            'TableController@destroy' => ['gestion.mesa', $d['message'] ?? "Eliminó la mesa #{$id}", 'mesa', $id, null],

            'UserController@store' => ['gestion.usuario', "Creó la cuenta de {$d['name']} (" . ($d['roles'][0]['name'] ?? 'sin rol') . ')', 'usuario', $d['id'] ?? null, null],
            'UserController@update' => ['gestion.usuario', "Editó la cuenta de {$d['name']} (" . ($d['roles'][0]['name'] ?? 'sin rol') . ')', 'usuario', $d['id'] ?? null, null],
            'UserController@destroy' => ['gestion.usuario', $d['message'] ?? "Eliminó la cuenta #{$id}", 'usuario', $id, null],

            'LoyaltyController@addPoints' => ['fidelizacion.xp', 'Otorgó ' . (int) $request->input('points') . ' XP a '
                . (User::find($request->input('user_id'))?->name ?? 'un comensal'), 'usuario', (int) $request->input('user_id'), null],
            'BadgeController@assignBadge' => ['fidelizacion.insignia', 'Asignó la insignia «' . (Badge::find($request->input('badge_id'))?->name ?? '?')
                . '» a ' . (User::find($request->input('user_id'))?->name ?? 'un comensal'), 'usuario', (int) $request->input('user_id'), null],
            'ChallengeController@store' => ['fidelizacion.reto', "Creó el reto «{$d['name']}»", 'reto', $d['id'] ?? null, null],
            'ChallengeController@updateProgress' => ['fidelizacion.reto', 'Registró un avance del reto «' . (Challenge::find($d['challenge_id'] ?? 0)?->name ?? '?')
                . '» de ' . (User::find($d['user_id'] ?? 0)?->name ?? 'un comensal') . ' (' . ($d['current_count'] ?? '?') . ')', 'reto', $d['challenge_id'] ?? null, null],
            'ChallengeController@join' => ['fidelizacion.union', 'Se unió al reto «' . (Challenge::find($request->input('challenge_id'))?->name ?? '?') . '»', 'reto', (int) $request->input('challenge_id'), null],

            default => null,   // consultas y acciones sin interés (p. ej. cambiar el estado de una mesa)
        };
    }

    /**
     * Inicio de sesión frenado por demasiados intentos. Lo llama el limitador «login»
     * (RouteServiceProvider), que responde antes de que la petición llegue aquí.
     */
    public static function inicioFrenado(Request $request): void
    {
        if (config('portal.modo') === 'portal') {
            return;
        }
        try {
            $cuenta = mb_strtolower(trim((string) $request->input('email')));
            $usuario = User::where(str_contains($cuenta, '@') ? 'email' : 'phone', $cuenta)->first();
            (new self())->guardar($request, response()->json([], 429), [
                'sesion.bloqueada', "Inicio de sesión frenado por demasiados intentos con «{$cuenta}»", 'usuario', $usuario?->id, $usuario,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el registro de auditoría: ' . $e->getMessage());
        }
    }

    private function guardar(Request $request, Response $response, array $registro): void
    {
        [$accion, $descripcion, $tipo, $sujeto, $usuario] = $registro;
        $usuario ??= $request->user();

        AuditLog::create([
            'user_id' => $usuario?->id,
            'user_name' => $usuario?->name,
            'user_role' => $usuario?->roles->pluck('name')->first(),
            'action' => $accion,
            'description' => mb_substr($descripcion, 0, 500),
            'subject_type' => $tipo,
            'subject_id' => $sujeto,
            'meta' => ['ruta' => $request->method() . ' /' . $request->path(), 'estado' => $response->getStatusCode()],
        ]);
    }

    private function idDeRuta(Request $request): ?int
    {
        $valor = collect($request->route()?->parameters() ?? [])->first();
        return is_numeric($valor) ? (int) $valor : (is_object($valor) && isset($valor->id) ? (int) $valor->id : null);
    }
}
