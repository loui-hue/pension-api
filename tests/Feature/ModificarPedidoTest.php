<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\MenuPensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cambio del pedido a último momento (principal preocupación de los propietarios,
 * entrevista del 20/10/2026): mientras el pedido está "pendiente" se pueden agregar,
 * quitar o cambiar platos; una vez en cocina ya no.
 */
class ModificarPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $comensal;
    private Table $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));   // lunes
        (new MenuPensionSeeder())->run();

        $this->mesero = $this->usuario('mesero');
        $this->comensal = $this->usuario('cliente');
        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol): User
    {
        $usuario = User::create(['name' => ucfirst($rol), 'email' => "$rol@test.local", 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    private function producto(string $nombre): Product
    {
        return Product::where('name', $nombre)->where('available', true)->firstOrFail();
    }

    /** El mesero registra: 1 Silpancho (14) + 1 Gelatina (3) = 17. */
    private function pedidoPendiente(): Order
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->postJson('/api/orders', [
            'table_id' => $this->mesa->id,
            'user_id' => $this->comensal->id,
            'items' => [
                ['product_id' => $this->producto('Silpancho')->id, 'quantity' => 1],
                ['product_id' => $this->producto('Gelatina')->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('id');

        return Order::findOrFail($id);
    }

    public function test_modificar_un_pedido_pendiente_recalcula_el_total(): void
    {
        $pedido = $this->pedidoPendiente();
        $this->assertEquals(17, $pedido->total);

        // El comensal cambia de idea: quita la gelatina, pide 2 silpanchos y un jugo.
        $this->putJson("/api/orders/{$pedido->id}", [
            'items' => [
                ['product_id' => $this->producto('Silpancho')->id, 'quantity' => 2],
                ['product_id' => $this->producto('Jugo natural sabor Tumbo')->id, 'quantity' => 1],
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'pendiente')
            ->assertJsonCount(2, 'items');

        $pedido->refresh();
        $this->assertEquals(43, $pedido->total);   // 2 x 14 + 15
        $this->assertEqualsCanonicalizing(
            ['Silpancho' => 28.0, 'Jugo natural sabor Tumbo' => 15.0],
            $pedido->items()->with('product')->get()
                ->mapWithKeys(fn ($i) => [$i->product->name => (float) $i->subtotal])->all()
        );
    }

    public function test_no_se_pueden_cambiar_los_platos_cuando_ya_esta_en_preparacion(): void
    {
        $pedido = $this->pedidoPendiente();
        $pedido->update(['status' => 'en_preparacion']);

        $this->putJson("/api/orders/{$pedido->id}", [
            'items' => [['product_id' => $this->producto('Asado')->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonPath('message', "El pedido #{$pedido->id} ya está en preparación: solo se pueden cambiar los platos mientras está pendiente, antes de enviarlo a cocina");

        $this->assertEquals(17, $pedido->fresh()->total);
        $this->assertSame(2, $pedido->items()->count());
    }

    public function test_cocina_y_cliente_no_pueden_modificar_pedidos(): void
    {
        $pedido = $this->pedidoPendiente();
        $cambio = ['items' => [['product_id' => $this->producto('Asado')->id, 'quantity' => 1]]];

        foreach (['cocina' => $this->usuario('cocina'), 'cliente' => $this->comensal] as $usuario) {
            Sanctum::actingAs($usuario);
            $this->putJson("/api/orders/{$pedido->id}", $cambio)->assertForbidden();
        }
        $this->assertEquals(17, $pedido->fresh()->total);
    }

    public function test_caja_puede_modificar_y_no_se_acepta_el_menu_de_otro_dia(): void
    {
        $pedido = $this->pedidoPendiente();
        Sanctum::actingAs($this->usuario('caja'));

        // Chairo es el menú del martes: hoy (lunes) no se puede agregar.
        $this->putJson("/api/orders/{$pedido->id}", [
            'items' => [['product_id' => Product::where('name', 'Chairo + Falso conejo')->firstOrFail()->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'No disponible hoy: Chairo + Falso conejo');

        $this->putJson("/api/orders/{$pedido->id}", [
            'items' => [['product_id' => $this->producto('Corbatines + Chuleta')->id, 'quantity' => 1]],
        ])->assertOk();
        $this->assertEquals(15, $pedido->fresh()->total);
    }

    public function test_cambiar_de_mesa_libera_la_anterior(): void
    {
        $pedido = $this->pedidoPendiente();
        $otra = Table::create(['number' => 2, 'capacity' => 4, 'status' => 'disponible']);

        $this->putJson("/api/orders/{$pedido->id}", ['table_id' => $otra->id])->assertOk();

        $this->assertSame('disponible', $this->mesa->fresh()->status);
        $this->assertSame('ocupada', $otra->fresh()->status);
    }
}
