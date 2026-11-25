<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Services\LoyaltyService;
use Carbon\Carbon;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\MenuPensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La insignia "Cliente Frecuente" promete un refresco del día de cortesía (el único
 * premio que mencionan los propietarios). La app lo entrega de verdad: el mesero lo
 * agrega al pedido a precio 0, una sola vez, y solo si el comensal tiene la insignia.
 */
class CortesiaInsigniaTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $ana;
    private Table $mesa;
    private Product $refresco;
    private Product $silpancho;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));   // lunes
        (new MenuPensionSeeder())->run();
        (new BadgeSeeder())->run();

        $this->mesero = $this->usuario('mesero', 'Juan Mesero');
        $this->ana = $this->usuario('cliente', 'Ana Comensal');
        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
        $this->refresco = Product::where('name', 'Refresco del día (vaso)')->firstOrFail();
        $this->silpancho = Product::where('name', 'Silpancho')->whereNull('day_of_week')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol, string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => strtolower(str_replace(' ', '.', $nombre)) . '@test.local', 'password' => 'secreto123']);
        $u->roles()->attach(Role::firstOrCreate(['name' => $rol])->id);
        return $u;
    }

    /** Ana llega a 100 XP y obtiene "Cliente Frecuente" (como al pagar sus pedidos). */
    private function anaEsClienteFrecuente(): void
    {
        app(LoyaltyService::class)->awardXp($this->ana, 100, 'Pedidos pagados');
    }

    private function pedir(array $items, ?User $comensal = null)
    {
        return $this->postJson('/api/orders', [
            'table_id' => $this->mesa->id,
            'user_id' => ($comensal ?? $this->ana)->id,
            'items' => $items,
        ]);
    }

    private function conCortesia(): array
    {
        return [
            ['product_id' => $this->silpancho->id, 'quantity' => 1],
            ['product_id' => $this->refresco->id, 'quantity' => 1, 'cortesia' => true],
        ];
    }

    public function test_la_insignia_dice_que_invita_y_que_producto(): void
    {
        Sanctum::actingAs($this->ana);
        $insignia = collect($this->getJson('/api/badges')->assertOk()->json())->firstWhere('name', 'Cliente Frecuente');

        $this->assertSame('Refresco del día (vaso)', $insignia['reward_product']['name']);
        $this->assertStringContainsString('una vez', $insignia['description']);
        $this->assertSame(1, Badge::whereNotNull('reward_product_id')->count());   // las demás solo reconocen
    }

    public function test_el_mesero_entrega_la_cortesia_a_precio_cero_y_queda_registrada(): void
    {
        $this->anaEsClienteFrecuente();
        Sanctum::actingAs($this->mesero);
        $this->assertSame('disponible', $this->getJson("/api/cortesias?user_id={$this->ana->id}")->json('0.estado'));

        $pedido = $this->pedir($this->conCortesia())->assertCreated();

        $this->assertEquals(14, $pedido->json('total'));   // solo el silpancho
        $refresco = collect($pedido->json('items'))->firstWhere('product_id', $this->refresco->id);
        $this->assertEquals(0, $refresco['subtotal']);
        $this->assertSame(Badge::where('name', 'Cliente Frecuente')->value('id'), $refresco['courtesy_badge_id']);

        $cortesia = $this->getJson("/api/cortesias?user_id={$this->ana->id}")->json('0');
        $this->assertSame('usada', $cortesia['estado']);
        $this->assertSame($pedido->json('id'), $cortesia['pedido_id']);

        $this->assertStringEndsWith('Bs. 14.00, con cortesía: Refresco del día (vaso)', AuditLog::latest('id')->value('description'));
    }

    public function test_la_cortesia_se_usa_una_sola_vez(): void
    {
        $this->anaEsClienteFrecuente();
        Sanctum::actingAs($this->mesero);
        $this->pedir($this->conCortesia())->assertCreated();

        $this->pedir($this->conCortesia())->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Ana Comensal no tiene una cortesía disponible de este producto (ya la usó o aún no tiene la insignia)');
        $this->assertSame(1, Order::count());
    }

    public function test_sin_la_insignia_o_con_mas_de_una_unidad_se_rechaza(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->pedir($this->conCortesia())->assertStatus(422);   // Ana aún no es cliente frecuente

        $this->anaEsClienteFrecuente();
        $this->pedir([['product_id' => $this->refresco->id, 'quantity' => 2, 'cortesia' => true]])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'La cortesía es una sola unidad');
        $this->pedir([['product_id' => $this->silpancho->id, 'quantity' => 1, 'cortesia' => true]])
            ->assertStatus(422);   // la cortesía es el refresco, no un plato cualquiera

        $this->assertSame(0, Order::count());
    }

    public function test_si_el_pedido_se_cancela_o_se_quita_el_refresco_la_cortesia_vuelve(): void
    {
        $this->anaEsClienteFrecuente();
        Sanctum::actingAs($this->mesero);

        $id = $this->pedir($this->conCortesia())->assertCreated()->json('id');
        $this->patchJson("/api/orders/{$id}/status", ['status' => 'cancelado'])->assertOk();
        $this->assertSame('disponible', $this->getJson("/api/cortesias?user_id={$this->ana->id}")->json('0.estado'));

        $id = $this->pedir($this->conCortesia())->assertCreated()->json('id');
        // Al modificar el pedido se puede mantener la cortesía (es la de este mismo pedido)...
        $this->assertEquals(14, $this->putJson("/api/orders/{$id}", ['items' => $this->conCortesia()])->assertOk()->json('total'));
        // ...o quitarla, y entonces queda libre para otro pedido.
        $this->putJson("/api/orders/{$id}", ['items' => [['product_id' => $this->silpancho->id, 'quantity' => 1]]])->assertOk();
        $this->assertSame('disponible', $this->getJson("/api/cortesias?user_id={$this->ana->id}")->json('0.estado'));
    }

    public function test_el_comensal_ve_solo_su_cortesia_y_la_puede_pedir_desde_su_app(): void
    {
        $this->anaEsClienteFrecuente();
        $otro = $this->usuario('cliente', 'Otro Comensal');

        Sanctum::actingAs($otro);
        $this->assertSame([], $this->getJson("/api/cortesias?user_id={$this->ana->id}")->assertOk()->json());

        Sanctum::actingAs($this->ana);
        $this->assertSame('disponible', $this->getJson('/api/cortesias')->json('0.estado'));
        $this->postJson('/api/orders', ['table_id' => $this->mesa->id, 'items' => $this->conCortesia()])->assertCreated();
        $this->assertSame('usada', $this->getJson('/api/cortesias')->json('0.estado'));
    }

    public function test_al_cobrar_la_cortesia_no_suma_xp_ni_cuenta_como_venta(): void
    {
        $this->anaEsClienteFrecuente();
        Sanctum::actingAs($this->mesero);
        $id = $this->pedir($this->conCortesia())->assertCreated()->json('id');
        Order::whereKey($id)->update(['status' => 'entregado']);

        $efectivo = PaymentMethod::create(['name' => 'Efectivo', 'requires_confirmation' => false, 'active' => true]);
        Sanctum::actingAs($this->usuario('caja', 'Pedro Caja'));
        $this->postJson('/api/payments', ['order_id' => $id, 'payment_method_id' => $efectivo->id, 'amount' => 14])->assertCreated();
        $this->assertSame(114, app(LoyaltyService::class)->totalXp($this->ana));   // 100 + 14 del silpancho

        Sanctum::actingAs($this->usuario('administrador', 'Doña Roberta'));
        $reporte = $this->getJson('/api/reports/dashboard?from=2026-11-16&to=2026-11-16')->assertOk();
        $this->assertSame(1, $reporte->json('loyalty.courtesies_given'));
        $this->assertNotContains('Refresco del día (vaso)', collect($reporte->json('top_products'))->pluck('name'));
        $this->assertSame(1, OrderItem::whereNotNull('courtesy_badge_id')->count());
    }
}
