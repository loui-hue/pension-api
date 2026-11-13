<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Category;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Streak;
use App\Models\Table;
use App\Models\User;
use App\Services\PortalSync;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portal en la nube: la pensión envía una copia de los datos de los comensales
 * y el comensal consulta su avance desde cualquier lugar.
 *
 * Se simula en una sola base de datos: se arma la copia, se vacían las tablas
 * (como un portal recién creado) y se aplica por el API en modo portal.
 */
class PortalNubeTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;
    private string $tokenPension;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Role::create(['name' => 'cliente']);
        $mesero = User::create(['name' => 'Juan Mesero', 'email' => 'mesero@test.local', 'password' => 'mesero123']);
        $mesero->roles()->attach(Role::create(['name' => 'mesero']));

        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@test.local', 'phone' => '71234567', 'password' => 'ana12345']);
        $this->ana->roles()->attach($cliente);

        $mesa = Table::create(['number' => 3, 'capacity' => 4, 'status' => 'disponible']);
        $sopa = Product::create(['category_id' => Category::create(['name' => 'Entradas'])->id, 'name' => 'Sopa de maní', 'price' => 10]);
        $pedido = Order::create(['user_id' => $this->ana->id, 'waiter_id' => $mesero->id, 'table_id' => $mesa->id, 'status' => 'pagado', 'total' => 10]);
        OrderItem::create(['order_id' => $pedido->id, 'product_id' => $sopa->id, 'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10]);
        LoyaltyPoint::create(['user_id' => $this->ana->id, 'points' => 40, 'total_xp' => 40]);
        Streak::create(['user_id' => $this->ana->id, 'current_streak' => 3, 'longest_streak' => 5, 'last_visit' => now()->toDateString()]);
        Badge::create(['name' => 'Primer Pedido', 'xp_required' => 0, 'type' => 'compras']);

        // Sesión que Ana abrió en la pensión.
        $this->tokenPension = $this->ana->createToken('auth_token')->plainTextToken;

        config(['portal.sync_token' => 'secreto-de-prueba']);
    }

    /** Arma la copia en "la pensión" y la aplica en "el portal" vacío. */
    private function sincronizarAlPortal(): void
    {
        config(['portal.modo' => 'local']);
        $copia = app(PortalSync::class)->snapshot();

        config(['portal.modo' => 'portal']);
        foreach (array_reverse(PortalSync::TABLAS) as $tabla) {
            DB::table($tabla)->delete();
        }
        $this->withToken('secreto-de-prueba')->postJson('/api/portal/sync', $copia)->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_la_copia_solo_lleva_datos_de_comensales(): void
    {
        $copia = app(PortalSync::class)->snapshot();

        $this->assertSame(['ana@test.local'], collect($copia['tablas']['users'])->pluck('email')->all());
        $this->assertSame(['cliente'], collect($copia['tablas']['roles'])->pluck('name')->all());
        $this->assertNull($copia['tablas']['orders'][0]['waiter_id']);   // el mesero no viaja a la nube
    }

    public function test_el_comensal_ve_su_avance_en_el_portal_con_la_misma_sesion(): void
    {
        $this->sincronizarAlPortal();

        $this->getJson('/api/ping')->assertOk()->assertJsonPath('modo', 'portal');

        $this->withToken($this->tokenPension)->getJson('/api/loyalty')->assertOk()->assertJsonPath('0.points', 40);
        $this->withToken($this->tokenPension)->postJson('/api/loyalty/streak')->assertOk()->assertJsonPath('current_streak', 3);
        $this->withToken($this->tokenPension)->getJson('/api/orders/my-orders')->assertOk()
            ->assertJsonPath('0.items.0.product.name', 'Sopa de maní');
    }

    public function test_en_el_portal_solo_entran_comensales_y_solo_a_consultar(): void
    {
        $this->sincronizarAlPortal();

        // El personal no existe en la nube.
        $this->postJson('/api/login', ['email' => 'mesero@test.local', 'password' => 'mesero123'])->assertStatus(401);

        // El comensal entra con su celular, pero no puede operar la pensión.
        $token = $this->postJson('/api/login', ['email' => '71234567', 'password' => 'ana12345'])
            ->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/orders', [])->assertNotFound();
        $this->withToken($token)->getJson('/api/products')->assertNotFound();
        $this->withToken($token)->getJson('/api/badges/user')->assertOk();
    }

    public function test_la_copia_se_aplica_aunque_en_el_portal_haya_datos_con_otros_ids(): void
    {
        // Lo que pasó el 11/11: el portal tenía otro rol "cliente" y otro comensal con
        // el mismo correo pero con ids distintos (datos de prueba que llegaron por error).
        config(['portal.modo' => 'local']);
        $copia = app(PortalSync::class)->snapshot();

        config(['portal.modo' => 'portal']);
        foreach (array_reverse(PortalSync::TABLAS) as $tabla) {
            DB::table($tabla)->delete();
        }
        DB::table('roles')->insert(['id' => 999, 'name' => 'cliente']);
        DB::table('users')->insert(['id' => 999, 'name' => 'Viejo', 'email' => 'ana@test.local', 'password' => 'x']);

        $this->withToken('secreto-de-prueba')->postJson('/api/portal/sync', $copia)->assertOk();

        $this->assertSame([$this->ana->id], DB::table('users')->pluck('id')->all());
        $this->assertSame(1, DB::table('roles')->where('name', 'cliente')->count());
    }

    public function test_sin_el_token_correcto_no_se_puede_sincronizar(): void
    {
        config(['portal.modo' => 'portal']);

        $this->postJson('/api/portal/sync', ['tablas' => []])->assertUnauthorized();
        $this->withToken('otro')->postJson('/api/portal/sync', ['tablas' => []])->assertUnauthorized();
        $this->assertSame(1, User::where('email', 'ana@test.local')->count());   // nada se borró
    }

    public function test_al_cobrar_en_la_pension_se_envia_la_copia_al_portal_sin_boton(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Sincronizado', 'filas' => []])]);
        config(['portal.modo' => 'local', 'portal.sync_url' => 'https://portal.prueba']);

        $caja = User::create(['name' => 'Caja', 'email' => 'caja@test.local', 'password' => 'caja12345']);
        $caja->roles()->attach(Role::create(['name' => 'caja']));
        Sanctum::actingAs($caja);

        $pedido = Order::create(['user_id' => $this->ana->id, 'table_id' => Table::first()->id, 'status' => 'entregado', 'total' => 20]);
        $efectivo = PaymentMethod::create(['name' => 'Efectivo']);

        $this->postJson('/api/payments', ['order_id' => $pedido->id, 'payment_method_id' => $efectivo->id, 'amount' => 20])
            ->assertCreated();

        // La copia salió sola tras el cobro, con el XP nuevo de Ana.
        Http::assertSentCount(1);
        $envio = Http::recorded()[0][0];
        $this->assertSame('https://portal.prueba/api/portal/sync', $envio->url());
        $this->assertSame(['Bearer secreto-de-prueba'], $envio->header('Authorization'));
        $this->assertEquals(60, collect($envio['tablas']['loyalty_points'])->sum('points'));   // 40 + 20 del cobro
    }

    public function test_consultar_no_envia_copias_al_portal(): void
    {
        Http::fake();
        config(['portal.modo' => 'local', 'portal.sync_url' => 'https://portal.prueba']);
        Sanctum::actingAs($this->ana);

        $this->getJson('/api/loyalty')->assertOk();
        $this->getJson('/api/orders/my-orders')->assertOk();

        Http::assertNothingSent();
    }

    public function test_en_la_pension_no_se_acepta_sincronizacion(): void
    {
        config(['portal.modo' => 'local']);

        $this->withToken('secreto-de-prueba')->postJson('/api/portal/sync', ['tablas' => []])->assertNotFound();
    }

    public function test_si_en_la_pension_restablecen_la_contrasena_se_cierran_sus_sesiones_del_portal(): void
    {
        $this->sincronizarAlPortal();
        $tokenPortal = $this->postJson('/api/login', ['email' => '71234567', 'password' => 'ana12345'])->json('token');

        // En la pensión le restablecen la contraseña y se envía una nueva copia.
        $hashAnterior = $this->ana->fresh()->password;
        config(['portal.modo' => 'local']);
        $this->ana->update(['password' => '71234567', 'must_change_password' => true]);
        $this->ana->tokens()->where('name', '!=', PortalSync::TOKEN_PORTAL)->delete();
        $copia = app(PortalSync::class)->snapshot();

        // Como la prueba usa una sola base, se deja "el portal" como estaba antes de
        // recibir la copia: con la contraseña anterior.
        DB::table('users')->where('id', $this->ana->id)->update(['password' => $hashAnterior, 'must_change_password' => false]);
        config(['portal.modo' => 'portal']);
        $this->withToken('secreto-de-prueba')->postJson('/api/portal/sync', $copia)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($tokenPortal)->getJson('/api/loyalty')->assertUnauthorized();
        $this->withToken($this->tokenPension)->getJson('/api/loyalty')->assertUnauthorized();
    }
}
