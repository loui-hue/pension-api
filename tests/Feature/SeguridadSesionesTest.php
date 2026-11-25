<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seguridad de las sesiones:
 * - al cambiar la contraseña se cierran las demás sesiones del usuario;
 * - los intentos fallidos de inicio de sesión se cuentan por cuenta: como todos los
 *   celulares llegan al servidor con la misma IP (Docker Desktop), contarlos por IP
 *   bloquearía a toda la pensión por los errores de una sola persona.
 */
class SeguridadSesionesTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(string $correo, string $password): User
    {
        $u = User::create(['name' => ucfirst(strtok($correo, '@')), 'email' => $correo, 'password' => $password]);
        $u->roles()->attach(Role::firstOrCreate(['name' => 'cliente'])->id);
        return $u;
    }

    public function test_cambiar_la_contrasena_cierra_las_demas_sesiones_y_conserva_la_actual(): void
    {
        $ana = $this->cuenta('ana@test.local', 'secreto123');
        $estaSesion = $ana->createToken('auth_token')->plainTextToken;
        $otroCelular = $ana->createToken('auth_token')->plainTextToken;
        $robada = $ana->createToken('auth_token')->plainTextToken;

        $this->withToken($estaSesion)->postJson('/api/me/password', [
            'current_password' => 'secreto123',
            'password' => 'nueva-clave-9',
            'password_confirmation' => 'nueva-clave-9',
        ])->assertOk();

        $this->assertSame(1, $ana->tokens()->count());
        $this->assertSame((int) strtok($estaSesion, '|'), $ana->tokens()->value('id'));

        foreach ([$otroCelular, $robada] as $token) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        }
        $this->app['auth']->forgetGuards();
        $this->withToken($estaSesion)->getJson('/api/me')->assertOk();
    }

    public function test_los_intentos_fallidos_de_una_cuenta_no_bloquean_a_las_demas(): void
    {
        $this->cuenta('ana@test.local', 'secreto123');
        $this->cuenta('carlos@test.local', 'clave-carlos');

        // Todas las peticiones de la prueba salen de la misma IP, como en la pensión.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ana@test.local', 'password' => 'equivocada'])->assertUnauthorized();
        }
        // El sexto intento de Ana se frena (aunque escriba el correo con mayúsculas o espacios).
        $this->postJson('/api/login', ['email' => ' ANA@test.local ', 'password' => 'secreto123'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.');

        // Carlos, desde la misma red, entra sin problema.
        $this->postJson('/api/login', ['email' => 'carlos@test.local', 'password' => 'clave-carlos'])->assertOk();
    }

    public function test_hay_un_tope_general_por_red_contra_probar_muchas_cuentas(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/login', ['email' => "nadie{$i}@test.local", 'password' => 'x'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'otro@test.local', 'password' => 'x'])->assertStatus(429);
    }
}
