<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inicio de sesión desde la app: el correo no distingue mayúsculas
 * (el teclado del celular suele capitalizar la primera letra) y la contraseña sí.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $mesero = User::create(['name' => 'Juan Mesero', 'email' => 'mesero@pension.com', 'password' => 'mesero123']);
        $mesero->roles()->attach(Role::create(['name' => 'mesero']));
    }

    public function test_correo_con_mayusculas_y_espacios_inicia_sesion(): void
    {
        $this->postJson('/api/login', ['email' => ' Mesero@Pension.com ', 'password' => 'mesero123'])
            ->assertOk()
            ->assertJsonPath('user.roles.0', 'mesero');
    }

    public function test_la_contrasena_si_distingue_mayusculas(): void
    {
        $this->postJson('/api/login', ['email' => 'mesero@pension.com', 'password' => 'Mesero123'])
            ->assertStatus(401);
    }

    public function test_sin_sesion_responde_401_y_no_500(): void
    {
        // Petición sin cabecera Accept: application/json (antes daba 500).
        $this->get('/api/orders')->assertStatus(401);
    }
}
