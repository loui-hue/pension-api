<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gestión del administrador (RF-06, RF-07, RF-08): productos, categorías,
 * usuarios del personal y mesas. Solo el administrador puede hacerlo, los datos
 * se validan con mensajes en español y nunca se borra el historial de pedidos.
 */
class GestionAdministradorTest extends TestCase
{
    use RefreshDatabase;

    private Category $categoria;
    private Table $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoria = Category::create(['name' => 'Almuerzos']);
        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
    }

    private function usuario(string $rol, string $correo = null): User
    {
        $u = User::create([
            'name' => ucfirst($rol), 'email' => $correo ?? "{$rol}@test.local", 'password' => 'secreto123',
        ]);
        $u->roles()->attach(Role::firstOrCreate(['name' => $rol])->id);
        return $u;
    }

    private function comoAdmin(): User
    {
        $admin = $this->usuario('administrador');
        Sanctum::actingAs($admin);
        return $admin;
    }

    private function pedidoCon(Product $producto, string $estado = 'pagado', ?Table $mesa = null): Order
    {
        $comensal = User::firstOrCreate(['email' => 'comensal@test.local'], ['name' => 'Comensal', 'password' => 'secreto123']);
        $pedido = Order::create([
            'user_id' => $comensal->id, 'table_id' => ($mesa ?? $this->mesa)->id, 'status' => $estado, 'total' => $producto->price,
        ]);
        OrderItem::create([
            'order_id' => $pedido->id, 'product_id' => $producto->id,
            'quantity' => 1, 'unit_price' => $producto->price, 'subtotal' => $producto->price,
        ]);
        return $pedido;
    }

    public function test_solo_el_administrador_gestiona_productos_categorias_usuarios_y_mesas(): void
    {
        $producto = Product::create(['category_id' => $this->categoria->id, 'name' => 'Sopa', 'price' => 10]);
        foreach (['mesero', 'cocina', 'caja', 'cliente'] as $rol) {
            Sanctum::actingAs($this->usuario($rol));
            $this->getJson('/api/products/gestion')->assertForbidden();
            $this->postJson('/api/products', ['category_id' => $this->categoria->id, 'name' => 'X', 'price' => 1])->assertForbidden();
            $this->putJson("/api/products/{$producto->id}", ['available' => false])->assertForbidden();
            $this->deleteJson("/api/products/{$producto->id}")->assertForbidden();
            $this->postJson('/api/categories', ['name' => 'Postres'])->assertForbidden();
            $this->putJson("/api/categories/{$this->categoria->id}", ['name' => 'Otra'])->assertForbidden();
            $this->deleteJson("/api/categories/{$this->categoria->id}")->assertForbidden();
            $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'secreto', 'role' => 'mesero'])->assertForbidden();
            $this->postJson('/api/tables', ['number' => 50, 'capacity' => 4])->assertForbidden();
            $this->putJson("/api/tables/{$this->mesa->id}", ['capacity' => 6])->assertForbidden();
            $this->deleteJson("/api/tables/{$this->mesa->id}")->assertForbidden();
        }
        $this->assertDatabaseHas('products', ['id' => $producto->id, 'available' => true]);
        $this->assertSame(1, Table::count());
        $this->assertSame(1, Category::count());
    }

    public function test_el_administrador_crea_edita_desactiva_y_elimina_un_producto(): void
    {
        $this->comoAdmin();

        $id = $this->postJson('/api/products', [
            'category_id' => $this->categoria->id, 'name' => 'Pique macho', 'description' => 'Con locoto', 'price' => 35,
        ])->assertCreated()->json('id');

        $this->putJson("/api/products/{$id}", ['name' => 'Pique macho grande', 'price' => 40.5])
            ->assertOk()->assertJsonPath('name', 'Pique macho grande');

        // No disponible: desaparece de la carta para pedir, pero sigue en la gestión.
        $this->putJson("/api/products/{$id}", ['available' => false])->assertOk();
        $this->assertNotContains($id, collect($this->getJson('/api/products')->json())->pluck('id'));
        $this->assertContains($id, collect($this->getJson('/api/products/gestion')->assertOk()->json())->pluck('id'));
        $this->postJson('/api/orders', ['table_id' => $this->mesa->id, 'items' => [['product_id' => $id, 'quantity' => 1]]])
            ->assertStatus(422);

        $this->deleteJson("/api/products/{$id}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_los_datos_del_producto_se_validan_en_espanol(): void
    {
        $this->comoAdmin();
        $this->postJson('/api/products', ['name' => '', 'price' => -1, 'category_id' => 999])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe el nombre del producto.')
            ->assertJsonPath('errors.price.0', 'El precio no puede ser negativo.')
            ->assertJsonPath('errors.category_id.0', 'La categoría elegida no existe.');
    }

    public function test_un_producto_con_pedidos_no_se_elimina_para_no_perder_el_historial(): void
    {
        $this->comoAdmin();
        $producto = Product::create(['category_id' => $this->categoria->id, 'name' => 'Sopa', 'price' => 10]);
        $pedido = $this->pedidoCon($producto);

        $this->deleteJson("/api/products/{$producto->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => '«Sopa» ya figura en pedidos registrados: no se puede eliminar. Márcalo como no disponible para que no se pueda pedir.']);
        $this->assertDatabaseHas('order_items', ['order_id' => $pedido->id, 'product_id' => $producto->id]);
    }

    public function test_el_administrador_crea_renombra_y_elimina_categorias(): void
    {
        $this->comoAdmin();
        $id = $this->postJson('/api/categories', ['name' => 'Postres'])->assertCreated()->json('id');
        $this->postJson('/api/categories', ['name' => 'Postres'])
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Ya existe una categoría con ese nombre.');
        $this->putJson("/api/categories/{$id}", ['name' => 'Postres caseros'])->assertOk()->assertJsonPath('name', 'Postres caseros');
        $this->deleteJson("/api/categories/{$id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_una_categoria_con_productos_no_se_elimina(): void
    {
        $this->comoAdmin();
        Product::create(['category_id' => $this->categoria->id, 'name' => 'Sopa', 'price' => 10]);
        $this->deleteJson("/api/categories/{$this->categoria->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'La categoría «Almuerzos» tiene 1 producto(s): muévelos a otra categoría o elimínalos antes.']);
        $this->assertSame(1, Product::count());
    }

    public function test_el_administrador_crea_una_cuenta_del_personal_con_contrasena_temporal(): void
    {
        $this->comoAdmin();
        $this->postJson('/api/users', [
            'name' => 'Rosa Mamani', 'email' => 'Rosa@Pension.bo', 'phone' => '71234567',
            'password' => 'temporal1', 'role' => 'cocina',
        ])->assertCreated()->assertJsonPath('roles.0.name', 'cocina');

        $rosa = User::where('email', 'rosa@pension.bo')->firstOrFail();
        $this->assertTrue((bool) $rosa->must_change_password);

        // Entra con la contraseña temporal y debe cambiarla.
        $this->postJson('/api/login', ['email' => 'rosa@pension.bo', 'password' => 'temporal1'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
    }

    public function test_los_datos_del_personal_se_validan(): void
    {
        $this->comoAdmin();
        $this->usuario('mesero', 'ocupado@test.local');
        $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'ocupado@test.local', 'phone' => '12ab', 'password' => '123', 'role' => 'cliente',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Ya existe un usuario con ese correo.')
            ->assertJsonPath('errors.phone.0', 'El celular debe tener solo números (7 a 15 dígitos).')
            ->assertJsonPath('errors.password.0', 'La contraseña temporal debe tener al menos 8 caracteres, con letras y números.')
            ->assertJsonPath('errors.role.0', 'El rol debe ser mesero, cocina, caja o administrador.');
    }

    public function test_el_administrador_edita_y_elimina_una_cuenta_del_personal(): void
    {
        $this->comoAdmin();
        $mesero = $this->usuario('mesero');

        $this->putJson("/api/users/{$mesero->id}", ['name' => 'Juan Pérez', 'phone' => '76543210', 'role' => 'caja'])
            ->assertOk()->assertJsonPath('name', 'Juan Pérez')->assertJsonPath('roles.0.name', 'caja');
        $this->assertSame(['caja'], $mesero->fresh()->roles->pluck('name')->all());

        $mesero->createToken('auth_token');
        $this->deleteJson("/api/users/{$mesero->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $mesero->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $mesero->id]);
    }

    public function test_el_administrador_no_puede_eliminarse_ni_quitarse_su_rol(): void
    {
        $admin = $this->comoAdmin();
        $this->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => 'No puedes eliminar tu propia cuenta.']);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'mesero'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'No puedes quitarte tu propio rol de administrador.']);
        $this->assertTrue($admin->fresh()->hasRole('administrador'));
    }

    public function test_las_cuentas_de_comensales_y_con_pedidos_no_se_eliminan_desde_usuarios(): void
    {
        $this->comoAdmin();
        $comensal = $this->usuario('cliente');
        $this->deleteJson("/api/users/{$comensal->id}")->assertStatus(422);
        $this->putJson("/api/users/{$comensal->id}", ['role' => 'administrador'])->assertStatus(422);
        $this->assertFalse($comensal->fresh()->hasRole('administrador'));

        $caja = $this->usuario('caja');
        Order::create(['user_id' => $caja->id, 'table_id' => $this->mesa->id, 'status' => 'pagado', 'total' => 10]);
        $this->deleteJson("/api/users/{$caja->id}")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $caja->id]);
    }

    public function test_el_administrador_agrega_edita_y_elimina_mesas(): void
    {
        $this->comoAdmin();
        $id = $this->postJson('/api/tables', ['number' => 12, 'capacity' => 6])
            ->assertCreated()->assertJsonPath('status', 'disponible')->json('id');
        $this->postJson('/api/tables', ['number' => 12, 'capacity' => 2])
            ->assertStatus(422)->assertJsonPath('errors.number.0', 'Ya existe una mesa con ese número.');
        $this->postJson('/api/tables', ['number' => 13, 'capacity' => 0])
            ->assertStatus(422)->assertJsonPath('errors.capacity.0', 'La capacidad debe ser de al menos 1 persona.');

        $this->putJson("/api/tables/{$id}", ['capacity' => 8])->assertOk()->assertJsonPath('capacity', 8);
        $this->deleteJson("/api/tables/{$id}")->assertOk();
        $this->assertDatabaseMissing('tables', ['id' => $id]);
    }

    public function test_una_mesa_con_pedidos_no_se_elimina(): void
    {
        $this->comoAdmin();
        $producto = Product::create(['category_id' => $this->categoria->id, 'name' => 'Sopa', 'price' => 10]);

        $enCurso = Table::create(['number' => 7, 'capacity' => 4, 'status' => 'ocupada']);
        $this->pedidoCon($producto, 'pendiente', $enCurso);
        $this->deleteJson("/api/tables/{$enCurso->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => 'La mesa 7 tiene 1 pedido(s) en curso: no se puede eliminar.']);

        $conHistorial = Table::create(['number' => 8, 'capacity' => 4, 'status' => 'disponible']);
        $this->pedidoCon($producto, 'pagado', $conHistorial);
        $this->deleteJson("/api/tables/{$conHistorial->id}")->assertStatus(422);

        $this->assertSame(3, Table::count());
        $this->assertSame(2, Order::count());
    }
}
