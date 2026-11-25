<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Con Docker Desktop todos los celulares llegan al servidor con la misma IP
        // (la puerta de enlace de Docker, 172.18.0.1). Por eso los intentos de inicio
        // de sesión se cuentan POR CUENTA: los errores de una persona no bloquean a las
        // demás. El tope por IP es solo un freno general contra probar muchas cuentas.
        $demasiados = fn () => response()->json([
            'message' => 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.',
        ], 429);

        RateLimiter::for('login', function (Request $request) use ($demasiados) {
            $cuenta = mb_strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinute(5)->by('login-cuenta:' . $cuenta)->response($demasiados),
                Limit::perMinute(60)->by('login-ip:' . $request->ip())->response($demasiados),
            ];
        });

        // Traer al Wi-Fi de la pensión la sesión iniciada en el portal: por sesión (token).
        RateLimiter::for('traer-sesion', function (Request $request) use ($demasiados) {
            return Limit::perMinute(10)
                ->by('traer-sesion:' . sha1((string) ($request->bearerToken() ?: $request->ip())))
                ->response($demasiados);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
