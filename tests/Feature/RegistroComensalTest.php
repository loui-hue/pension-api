<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de integración 5: el mesero registra al comensal al tomar el pedido
 * y el comensal entra a la app con su teléfono.
 */
class RegistroComensalTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConRol(string $rol, string $email): User
    {
        $usuario = User::create(['name' => ucfirst($rol) . ' de prueba', 'email' => $email, 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'cliente']);
    }

    public function test_mesero_registra_comensal_con_nombre_y_telefono(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));

        $respuesta = $this->postJson('/api/customers', ['name' => ' Rosa Quispe ', 'phone' => '71234567']);

        $respuesta->assertCreated()
            ->assertJsonPath('name', 'Rosa Quispe')
            ->assertJsonPath('email', '71234567@comensal.local')
            ->assertJsonPath('roles.0.name', 'cliente');

        // Aparece en el buscador de comensales del pedido.
        $this->getJson('/api/users?role=cliente&search=7123')
            ->assertOk()
            ->assertJsonPath('0.name', 'Rosa Quispe');
    }

    public function test_telefono_repetido_o_invalido_se_rechaza(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));
        $this->postJson('/api/customers', ['name' => 'Rosa', 'phone' => '71234567'])->assertCreated();

        $this->postJson('/api/customers', ['name' => 'Otra Rosa', 'phone' => '71234567'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/customers', ['name' => 'Sin número', 'phone' => '7123-abc'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/customers', ['phone' => '79999999'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_un_cliente_no_puede_registrar_comensales(): void
    {
        Sanctum::actingAs($this->usuarioConRol('cliente', 'cliente@test.local'));

        $this->postJson('/api/customers', ['name' => 'Alguien', 'phone' => '71112222'])->assertForbidden();
    }

    public function test_comensal_registrado_entra_con_su_telefono(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));
        $this->postJson('/api/customers', ['name' => 'Rosa Quispe', 'phone' => '71234567'])->assertCreated();

        // La contraseña inicial es el mismo teléfono.
        $this->postJson('/api/login', ['email' => ' 71234567 ', 'password' => '71234567'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Rosa Quispe')
            ->assertJsonPath('user.roles.0', 'cliente')
            ->assertJsonPath('user.must_change_password', true);

        $this->postJson('/api/login', ['email' => '71234567', 'password' => 'otra'])->assertStatus(401);
    }

    public function test_primer_ingreso_obliga_a_crear_contrasena_propia(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));
        $id = $this->postJson('/api/customers', ['name' => 'Rosa Quispe', 'phone' => '71234567'])->json('id');
        $comensal = User::findOrFail($id);
        Sanctum::actingAs($comensal);

        // Con la contraseña temporal no puede usar el resto del sistema.
        $this->getJson('/api/loyalty')->assertForbidden()->assertJsonPath('must_change_password', true);
        $this->getJson('/api/me')->assertOk();

        // Validaciones del cambio.
        $this->postJson('/api/me/password', [
            'current_password' => 'incorrecta', 'password' => 'rosa2026', 'password_confirmation' => 'rosa2026',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/me/password', [
            'current_password' => '71234567', 'password' => 'rosa2026', 'password_confirmation' => 'otra2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/me/password', [
            'current_password' => '71234567', 'password' => '123', 'password_confirmation' => '123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Cambio correcto: queda desbloqueado.
        $this->postJson('/api/me/password', [
            'current_password' => '71234567', 'password' => 'rosa2026', 'password_confirmation' => 'rosa2026',
        ])->assertOk();
        $this->assertFalse($comensal->fresh()->must_change_password);
        Sanctum::actingAs($comensal->fresh());
        $this->getJson('/api/loyalty')->assertOk();

        // Desde ahora entra con su contraseña y ya no con el celular.
        $this->postJson('/api/login', ['email' => '71234567', 'password' => 'rosa2026'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->postJson('/api/login', ['email' => '71234567', 'password' => '71234567'])->assertStatus(401);
    }

    public function test_no_puede_usar_su_celular_como_contrasena_nueva(): void
    {
        $comensal = User::create([
            'name' => 'Rosa', 'email' => 'rosa@test.local', 'phone' => '71234567',
            'password' => 'temporal1', 'must_change_password' => true,
        ]);
        Sanctum::actingAs($comensal);

        $this->postJson('/api/me/password', [
            'current_password' => 'temporal1', 'password' => '71234567', 'password_confirmation' => '71234567',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_el_personal_no_tiene_que_cambiar_contrasena(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));

        $this->getJson('/api/orders')->assertOk();
    }
}
