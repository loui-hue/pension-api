<?php

namespace Tests\Feature;

use App\Models\Category;
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
 * Carta real de la pensión: los platos principales, bebidas y postres se ofrecen
 * todos los días; el menú del día cambia según el día (lunes a sábado).
 */
class CartaPorDiaTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-11-16 12:00:00';
    private const SABADO = '2026-11-21 12:00:00';
    private const DOMINGO = '2026-11-22 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // Producto de la carta anterior con un pedido en el historial: no debe borrarse.
        $entradas = Category::create(['name' => 'Entradas']);
        Product::create(['category_id' => $entradas->id, 'name' => 'Pique Macho', 'price' => 20]);

        (new MenuPensionSeeder())->run();

        $comensal = User::create(['name' => 'Comensal', 'email' => 'c@test.local', 'password' => 'secreto123']);
        $comensal->roles()->attach(Role::create(['name' => 'cliente']));
        Sanctum::actingAs($comensal);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nombresDeHoy(): array
    {
        return collect($this->getJson('/api/products')->assertOk()->json())->pluck('name')->all();
    }

    public function test_el_lunes_se_ofrece_la_carta_fija_y_solo_el_menu_del_lunes(): void
    {
        $this->travelTo(Carbon::parse(self::LUNES));
        $nombres = $this->nombresDeHoy();

        foreach (['Silpancho', 'Asado', 'Chuleta', 'Gelatina', 'Refresco CIN de mocochinchi',
                  'Corbatines + Chuleta', 'Corbatines + Tomatada de pollo', 'Solo sopa: Corbatines'] as $esperado) {
            $this->assertContains($esperado, $nombres);
        }
        $this->assertNotContains('Chairo + Falso conejo', $nombres);   // menú del martes
        $this->assertNotContains('Caldo de pollo', $nombres);          // menú del sábado
        $this->assertNotContains('Pique Macho', $nombres);             // carta anterior

        $precios = collect($this->getJson('/api/products')->json())->pluck('price', 'name');
        $this->assertEquals(14, $precios['Silpancho']);
        $this->assertEquals(15, $precios['Corbatines + Chuleta']);
        $this->assertEquals(7, $precios['Solo sopa: Corbatines']);
        $this->assertEquals(8, $precios['Refresco del día (jarra 1/2 L)']);
    }

    public function test_el_sabado_el_menu_del_dia_es_caldo_de_pollo_a_12_bs(): void
    {
        $this->travelTo(Carbon::parse(self::SABADO));
        $menu = collect($this->getJson('/api/products')->json())
            ->where('category.name', 'Menú del día');

        $this->assertSame(['Caldo de pollo'], $menu->pluck('name')->values()->all());
        $this->assertEquals(12, $menu->first()['price']);
    }

    public function test_el_domingo_no_hay_menu_del_dia(): void
    {
        $this->travelTo(Carbon::parse(self::DOMINGO));
        $hoy = collect($this->getJson('/api/products')->json());

        $this->assertCount(0, $hoy->where('category.name', 'Menú del día'));
        $this->assertContains('Silpancho', $hoy->pluck('name'));
    }

    public function test_entradas_ya_no_es_una_categoria_y_la_carta_anterior_no_se_borra(): void
    {
        $categorias = collect($this->getJson('/api/categories')->json())->pluck('name');
        $this->assertNotContains('Entradas', $categorias);
        $this->assertEquals(['Platos principales', 'Bebidas', 'Postres', 'Menú del día'],
            $categorias->reject(fn ($n) => $n === 'Entradas')->values()->all());

        $anterior = Product::where('name', 'Pique Macho')->firstOrFail();
        $this->assertFalse($anterior->available);

        // Volver a cargar la carta no duplica productos.
        $total = Product::count();
        (new MenuPensionSeeder())->run();
        $this->assertSame($total, Product::count());
    }

    public function test_no_se_puede_pedir_el_menu_de_otro_dia(): void
    {
        $this->travelTo(Carbon::parse(self::LUNES));
        $mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
        $menuMartes = Product::where('name', 'Chairo + Falso conejo')->firstOrFail();
        $menuLunes = Product::where('name', 'Corbatines + Chuleta')->firstOrFail();

        $this->postJson('/api/orders', [
            'table_id' => $mesa->id,
            'items' => [['product_id' => $menuMartes->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'No disponible hoy: Chairo + Falso conejo');

        $this->postJson('/api/orders', [
            'table_id' => $mesa->id,
            'items' => [['product_id' => $menuLunes->id, 'quantity' => 1]],
        ])->assertCreated();
    }
}
