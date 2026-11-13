<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Copia de los datos de los comensales entre el servidor de la pensión y el
 * portal en la nube. El servidor local arma la copia (snapshot) y el portal la
 * aplica: inserta o actualiza por id y borra lo que ya no existe en la pensión.
 */
class PortalSync
{
    /** Orden de aplicación: primero las tablas "padre", luego las que dependen de ellas. */
    public const TABLAS = [
        'roles', 'users', 'role_user',
        'categories', 'products', 'tables',
        'badges', 'challenges',
        'orders', 'order_items',
        'loyalty_points', 'streaks', 'user_badges', 'user_challenges',
        'personal_access_tokens',
    ];

    /** Nombre de los tokens creados al iniciar sesión en el portal (no vienen de la pensión). */
    public const TOKEN_PORTAL = 'portal_token';

    /**
     * Envía la copia al portal apenas termina la petición actual (cobro, pedido...),
     * sin que el usuario espere. Si no hay Internet no pasa nada: la revisión de
     * cada minuto la envía cuando vuelva la conexión.
     */
    public static function programar(): void
    {
        if (config('portal.modo') !== 'local' || !config('portal.sync_url')) {
            return;
        }
        dispatch(fn () => Artisan::call('portal:sincronizar'))->afterResponse();
    }

    /** Arma la copia en el servidor de la pensión. Solo datos de comensales. */
    public function snapshot(): array
    {
        $comensales = User::whereHas('roles', fn ($q) => $q->where('name', 'cliente'))->pluck('id');
        $pedidos = DB::table('orders')->whereIn('user_id', $comensales)->pluck('id');

        return [
            'generado_en' => now()->toIso8601String(),
            'tablas' => [
                'roles' => DB::table('roles')->where('name', 'cliente')->get(),
                'users' => DB::table('users')->whereIn('id', $comensales)->get([
                    'id', 'name', 'email', 'phone', 'password', 'must_change_password',
                    'status', 'created_at', 'updated_at',
                ]),
                'role_user' => DB::table('role_user')->whereIn('user_id', $comensales)->get(),
                'categories' => DB::table('categories')->get(),
                'products' => DB::table('products')->get(),
                'tables' => DB::table('tables')->get(),
                'badges' => DB::table('badges')->get(),
                'challenges' => DB::table('challenges')->get(),
                // El mesero no se copia: el portal no tiene cuentas del personal.
                'orders' => DB::table('orders')->whereIn('id', $pedidos)->get()
                    ->map(fn ($o) => array_merge((array) $o, ['waiter_id' => null])),
                'order_items' => DB::table('order_items')->whereIn('order_id', $pedidos)->get(),
                'loyalty_points' => DB::table('loyalty_points')->whereIn('user_id', $comensales)->get(),
                'streaks' => DB::table('streaks')->whereIn('user_id', $comensales)->get(),
                'user_badges' => DB::table('user_badges')->whereIn('user_id', $comensales)->get(),
                'user_challenges' => DB::table('user_challenges')->whereIn('user_id', $comensales)->get(),
                // Sesiones abiertas en la pensión: el mismo inicio de sesión sirve en el portal.
                'personal_access_tokens' => DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $comensales)
                    ->where('name', '!=', self::TOKEN_PORTAL)
                    ->get(),
            ],
        ];
    }

    /** Aplica la copia en el portal. Devuelve cuántas filas quedó de cada tabla. */
    public function aplicar(array $snapshot): array
    {
        $tablas = $snapshot['tablas'] ?? [];

        return DB::transaction(function () use ($tablas) {
            $passwordsAntes = DB::table('users')->pluck('password', 'id');

            // 1) Primero borrar lo que ya no está en la pensión, de hijas a padres.
            //    Va antes que insertar: si en la pensión un dato cambió de id (p. ej. al
            //    volver a sembrar la base), el registro viejo con el mismo nombre o correo
            //    se quita antes y no choca con los índices únicos (roles.name, users.email).
            foreach (array_reverse(self::TABLAS) as $tabla) {
                $ids = array_column(array_map(fn ($f) => (array) $f, $tablas[$tabla] ?? []), 'id');
                $consulta = DB::table($tabla);
                if ($tabla === 'personal_access_tokens') {
                    // Las sesiones abiertas en el portal se conservan.
                    $consulta->where('name', '!=', self::TOKEN_PORTAL);
                }
                $ids ? $consulta->whereNotIn('id', $ids)->delete() : $consulta->delete();
            }

            // 2) Insertar o actualizar, de padres a hijas.
            foreach (self::TABLAS as $tabla) {
                $filas = array_map(fn ($f) => (array) $f, $tablas[$tabla] ?? []);
                foreach (array_chunk($filas, 500) as $lote) {
                    $columnas = array_values(array_diff(array_keys($lote[0]), ['id']));
                    DB::table($tabla)->upsert($lote, ['id'], $columnas);
                }
            }

            // 3) Si en la pensión cambió la contraseña (o se restableció) o el comensal
            //    ya no existe, se cierran también sus sesiones abiertas en el portal.
            $passwordsAhora = DB::table('users')->pluck('password', 'id');
            $cerrar = $passwordsAntes->filter(fn ($hash, $id) => ($passwordsAhora[$id] ?? null) !== $hash)->keys();
            if ($cerrar->isNotEmpty()) {
                DB::table('personal_access_tokens')
                    ->where('name', self::TOKEN_PORTAL)
                    ->whereIn('tokenable_id', $cerrar)
                    ->delete();
            }

            // 4) Los tokens creados en el portal usan ids altos para no chocar con
            //    los que llegan de la pensión.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval('personal_access_tokens_id_seq', GREATEST(1000000000, (SELECT COALESCE(MAX(id), 0) FROM personal_access_tokens)))");
            }

            cache()->forever('portal.sincronizado_en', now()->toIso8601String());

            return collect(self::TABLAS)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        });
    }
}
