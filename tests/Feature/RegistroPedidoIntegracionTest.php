<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de integración 1: registro de un pedido.
 * Componentes integrados: OrderController + Product + Table + OrderItem + base de datos.
 */
class RegistroPedidoIntegracionTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConRol(string $rol, string $email): User
    {
        $usuario = User::create(['name' => ucfirst($rol) . ' de prueba', 'email' => $email, 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    public function test_registrar_pedido_calcula_total_y_ocupa_la_mesa(): void
    {
        // Arrange: un mesero autenticado, un comensal registrado, una mesa libre y dos productos con precio conocido.
        $mesero = $this->usuarioConRol('mesero', 'mesero@test.local');
        $comensal = $this->usuarioConRol('cliente', 'comensal@test.local');
        Sanctum::actingAs($mesero);

        $mesa = Table::create(['number' => 3, 'capacity' => 4, 'status' => 'disponible']);
        $categoria = Category::create(['name' => 'Almuerzos']);
        $sopa = Product::create(['category_id' => $categoria->id, 'name' => 'Sopa de maní', 'price' => 15.00]);
        $refresco = Product::create(['category_id' => $categoria->id, 'name' => 'Refresco de mocochinchi', 'price' => 8.50]);

        // Act: se envía el pedido a la API (2 sopas + 1 refresco).
        $respuesta = $this->postJson('/api/orders', [
            'table_id' => $mesa->id,
            'user_id' => $comensal->id,
            'items' => [
                ['product_id' => $sopa->id, 'quantity' => 2],
                ['product_id' => $refresco->id, 'quantity' => 1],
            ],
        ]);

        // Assert: el pedido se crea con el total correcto, sus detalles y la mesa queda ocupada.
        $respuesta->assertCreated();
        $pedido = Order::with('items')->findOrFail($respuesta->json('id'));

        $this->assertSame('pendiente', $pedido->status);
        $this->assertEquals(38.50, (float) $pedido->total);   // 2 x 15.00 + 1 x 8.50
        $this->assertCount(2, $pedido->items);
        $this->assertEquals(30.00, (float) $pedido->items->firstWhere('product_id', $sopa->id)->subtotal);
        $this->assertSame('ocupada', $mesa->fresh()->status);

        // El pedido queda a nombre del comensal (quien recibirá el XP) y registra al mesero.
        $this->assertEquals($comensal->id, $pedido->user_id);
        $this->assertEquals($mesero->id, $pedido->waiter_id);
    }

    public function test_mesero_no_puede_registrar_pedido_sin_comensal(): void
    {
        $mesero = $this->usuarioConRol('mesero', 'mesero@test.local');
        $otroMesero = $this->usuarioConRol('mesero', 'mesero2@test.local');
        Sanctum::actingAs($mesero);

        $mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
        $categoria = Category::create(['name' => 'Almuerzos']);
        $sopa = Product::create(['category_id' => $categoria->id, 'name' => 'Sopa', 'price' => 15.00]);
        $datos = ['table_id' => $mesa->id, 'items' => [['product_id' => $sopa->id, 'quantity' => 1]]];

        // Sin comensal, o eligiendo a alguien que no es comensal, se rechaza.
        $this->postJson('/api/orders', $datos)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/orders', $datos + ['user_id' => $otroMesero->id])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame('disponible', $mesa->fresh()->status);
    }

    public function test_cliente_registra_pedido_a_su_propio_nombre(): void
    {
        $cliente = $this->usuarioConRol('cliente', 'cliente@test.local');
        Sanctum::actingAs($cliente);

        $mesa = Table::create(['number' => 2, 'capacity' => 2, 'status' => 'disponible']);
        $categoria = Category::create(['name' => 'Bebidas']);
        $refresco = Product::create(['category_id' => $categoria->id, 'name' => 'Refresco', 'price' => 8.50]);

        $respuesta = $this->postJson('/api/orders', [
            'table_id' => $mesa->id,
            'items' => [['product_id' => $refresco->id, 'quantity' => 1]],
        ]);

        $respuesta->assertCreated()
            ->assertJsonPath('user_id', $cliente->id)
            ->assertJsonPath('waiter_id', null);
    }
}
