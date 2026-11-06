<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Challenge;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Streak;
use App\Models\Table;
use App\Models\User;
use App\Models\UserChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de seguridad: cada rol solo puede usar las funciones que le corresponden,
 * aunque conozca la dirección del API (no basta con ocultar los botones en la app).
 */
class PermisosPorRolTest extends TestCase
{
    use RefreshDatabase;

    private Category $categoria;
    private Table $mesa;

    private function como(string $rol): User
    {
        $usuario = User::firstOrCreate(
            ['email' => "{$rol}@test.local"],
            ['name' => ucfirst($rol), 'password' => 'secreto123']
        );
        $usuario->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => $rol])->id]);
        Sanctum::actingAs($usuario);
        return $usuario;
    }

    private function pedido(string $estado = 'pendiente'): Order
    {
        $comensal = User::firstOrCreate(['email' => 'comensal@test.local'], ['name' => 'Comensal', 'password' => 'secreto123']);
        return Order::create(['user_id' => $comensal->id, 'table_id' => $this->mesa->id, 'status' => $estado, 'total' => 20]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoria = Category::create(['name' => 'Almuerzos']);
        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'ocupada']);
    }

    public function test_el_cliente_solo_usa_sus_funciones(): void
    {
        $this->como('cliente');
        $producto = ['category_id' => $this->categoria->id, 'name' => 'Pirata', 'price' => 1];

        // Puede ver el menú y las mesas, y hacer su propio pedido.
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/tables')->assertOk();
        $this->getJson('/api/orders/my-orders')->assertOk();

        // No puede tocar el menú, ver pedidos ajenos, cobrar, ver usuarios ni reportes.
        $this->postJson('/api/products', $producto)->assertForbidden();
        $this->deleteJson("/api/categories/{$this->categoria->id}")->assertForbidden();
        $this->getJson('/api/orders')->assertForbidden();
        $this->getJson('/api/payments')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/reports/dashboard')->assertForbidden();
        $this->patchJson("/api/tables/{$this->mesa->id}/status", ['status' => 'disponible'])->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public function test_el_cliente_no_puede_regalarse_xp_ni_completar_retos(): void
    {
        $cliente = $this->como('cliente');
        $reto = Challenge::create(['name' => 'Cinco visitas', 'xp_reward' => 100, 'type' => 'visitas', 'active' => true]);
        $this->postJson('/api/challenges/join', ['challenge_id' => $reto->id])->assertCreated();
        $participacion = UserChallenge::where('user_id', $cliente->id)->firstOrFail();

        $this->patchJson("/api/challenges/{$participacion->id}/progress", ['progress' => 100])->assertForbidden();
        $this->postJson('/api/loyalty/points', ['user_id' => $cliente->id, 'points' => 500])->assertForbidden();
        $this->postJson('/api/badges/assign', ['user_id' => $cliente->id, 'badge_id' => 1])->assertForbidden();

        $this->assertSame(0, (int) LoyaltyPoint::where('user_id', $cliente->id)->sum('points'));
        $this->assertSame('en_progreso', $participacion->fresh()->status);
    }

    public function test_abrir_fidelizacion_no_cuenta_como_visita(): void
    {
        $cliente = $this->como('cliente');

        // Consultar la racha varias veces no la incrementa: la visita se registra al pagar.
        $this->postJson('/api/loyalty/streak')->assertOk()->assertJsonPath('current_streak', 0);
        $this->postJson('/api/loyalty/streak')->assertOk()->assertJsonPath('current_streak', 0);
        $this->assertSame(0, Streak::where('user_id', $cliente->id)->count());
    }

    public function test_cada_rol_solo_cambia_los_estados_que_le_tocan(): void
    {
        $pedido = $this->pedido();

        // El mesero envía el pedido a cocina (como el botón "Enviar a Cocina" de la app).
        $this->como('mesero');
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'en_preparacion'])->assertOk();
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'listo'])->assertForbidden();

        $this->como('cocina');
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'listo'])->assertOk();
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'entregado'])->assertForbidden();
        $this->getJson('/api/payments')->assertForbidden();

        $this->como('mesero');
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'entregado'])->assertOk();

        // Nadie lo marca "pagado" a mano: solo al cobrarlo (y así se acredita el XP).
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'pagado'])->assertStatus(422);
        $this->assertSame('entregado', $pedido->fresh()->status);
    }

    public function test_un_pedido_pagado_o_cancelado_ya_no_cambia_de_estado(): void
    {
        $pagado = $this->pedido('pagado');
        $this->como('mesero');

        $this->patchJson("/api/orders/{$pagado->id}/status", ['status' => 'cancelado'])->assertStatus(422);
        $this->assertSame('pagado', $pagado->fresh()->status);
    }

    public function test_el_personal_tiene_acceso_a_lo_suyo(): void
    {
        $this->como('mesero');
        $this->getJson('/api/users?role=cliente')->assertOk();         // buscar comensales
        $this->getJson('/api/orders')->assertOk();
        $this->postJson('/api/users', [])->assertForbidden();           // crear cuentas: solo admin
        $this->getJson('/api/payments')->assertForbidden();             // cobrar: solo caja

        $this->como('caja');
        $this->getJson('/api/payments')->assertOk();
        $this->getJson('/api/payment-methods')->assertOk();
        $this->postJson('/api/products', ['category_id' => $this->categoria->id, 'name' => 'X', 'price' => 1])
            ->assertForbidden();
    }

    public function test_el_administrador_puede_todo(): void
    {
        $this->como('administrador');

        $this->postJson('/api/products', ['category_id' => $this->categoria->id, 'name' => 'Sajta de pollo', 'price' => 25])
            ->assertCreated();
        $this->getJson('/api/payments')->assertOk();
        $this->getJson('/api/orders')->assertOk();
        $this->getJson('/api/reports/dashboard')->assertOk();
        $this->getJson('/api/users')->assertOk();
    }
}
