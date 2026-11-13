<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de integración 6: recuperación de contraseña asistida por el personal.
 * El comensal olvidó su contraseña; el mesero la restablece tras verificarlo en
 * persona y el comensal crea una nueva al entrar con su celular.
 */
class RecuperarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $comensal;

    private function usuarioConRol(string $rol, string $email, array $extra = []): User
    {
        $usuario = User::create(array_merge(
            ['name' => ucfirst($rol) . ' de prueba', 'email' => $email, 'password' => 'secreto123'],
            $extra
        ));
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Comensal que ya había creado su contraseña propia y la olvidó.
        $this->comensal = $this->usuarioConRol('cliente', 'rosa@test.local', [
            'name' => 'Rosa Quispe', 'phone' => '71234567', 'password' => 'olvidada99',
        ]);
        $this->comensal->createToken('celular_viejo');
    }

    public function test_mesero_restablece_y_el_comensal_crea_una_nueva(): void
    {
        $mesero = $this->usuarioConRol('mesero', 'mesero@test.local');
        Sanctum::actingAs($mesero);

        $this->postJson("/api/customers/{$this->comensal->id}/reset-password")->assertOk();

        $rosa = $this->comensal->fresh();
        $this->assertTrue($rosa->must_change_password);
        $this->assertEquals($mesero->id, $rosa->password_reset_by);
        $this->assertNotNull($rosa->password_reset_at);
        $this->assertSame(0, $rosa->tokens()->count());   // sesiones anteriores cerradas

        // La contraseña olvidada ya no sirve; entra con su celular y debe cambiarla.
        $this->postJson('/api/login', ['email' => '71234567', 'password' => 'olvidada99'])->assertStatus(401);
        $this->postJson('/api/login', ['email' => '71234567', 'password' => '71234567'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_un_cliente_no_puede_restablecer_contrasenas(): void
    {
        Sanctum::actingAs($this->usuarioConRol('cliente', 'otro@test.local', ['phone' => '70000099']));

        $this->postJson("/api/customers/{$this->comensal->id}/reset-password")->assertForbidden();
        $this->postJson('/api/login', ['email' => '71234567', 'password' => 'olvidada99'])->assertOk();
    }

    public function test_el_mesero_no_puede_restablecer_cuentas_del_personal(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));
        $admin = $this->usuarioConRol('administrador', 'admin@test.local', ['phone' => '70000001']);

        $this->postJson("/api/customers/{$admin->id}/reset-password")->assertStatus(422);
        $this->assertFalse((bool) $admin->fresh()->must_change_password);
    }
}
