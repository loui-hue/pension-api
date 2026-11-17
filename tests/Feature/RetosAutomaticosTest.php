<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Challenge;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\UserChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los retos avanzan solos cuando caja cobra un pedido del comensal (objetivo
 * específico 5: la fidelización asigna los incentivos de forma automática).
 * Componentes integrados: PaymentController + LoyaltyService + Challenge + UserChallenge.
 */
class RetosAutomaticosTest extends TestCase
{
    use RefreshDatabase;

    private User $comensal;
    private Table $mesa;
    private PaymentMethod $efectivo;
    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comensal = User::create([
            'name' => 'Comensal', 'email' => 'comensal@test.local', 'password' => 'secreto123',
        ]);
        $cajero = User::create([
            'name' => 'Cajero', 'email' => 'cajero@test.local', 'password' => 'secreto123',
        ]);
        $cajero->roles()->attach(Role::create(['name' => 'caja']));
        Sanctum::actingAs($cajero);

        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'ocupada']);
        $this->efectivo = PaymentMethod::create(['name' => 'Efectivo']);
        $this->categoria = Category::create(['name' => 'Platos']);
    }

    private function reto(string $tipo, int $meta, int $xp = 50, array $extra = []): Challenge
    {
        $reto = Challenge::create(array_merge([
            'name' => "Reto $tipo", 'xp_reward' => $xp, 'goal' => $meta, 'type' => $tipo,
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(30), 'active' => true,
        ], $extra));
        UserChallenge::create([
            'user_id' => $this->comensal->id, 'challenge_id' => $reto->id,
            'status' => 'en_progreso', 'progress' => 0,
        ]);
        return $reto;
    }

    private function producto(string $nombre): Product
    {
        return Product::create(['category_id' => $this->categoria->id, 'name' => $nombre, 'price' => 10]);
    }

    /** Crea un pedido entregado con esos productos y caja lo cobra en efectivo. */
    private function pagarPedido(array $productos = []): Order
    {
        $pedido = Order::create([
            'user_id' => $this->comensal->id, 'table_id' => $this->mesa->id,
            'status' => 'entregado', 'total' => 10 * max(1, count($productos)),
        ]);
        foreach ($productos as $producto) {
            OrderItem::create([
                'order_id' => $pedido->id, 'product_id' => $producto->id,
                'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10,
            ]);
        }

        $this->postJson('/api/payments', [
            'order_id' => $pedido->id, 'payment_method_id' => $this->efectivo->id, 'amount' => $pedido->total,
        ])->assertCreated();

        return $pedido;
    }

    private function participacion(Challenge $reto): UserChallenge
    {
        return UserChallenge::where('challenge_id', $reto->id)->where('user_id', $this->comensal->id)->firstOrFail();
    }

    private function xpDeRetos(): int
    {
        return (int) LoyaltyPoint::where('user_id', $this->comensal->id)
            ->where('reason', 'like', 'Reto completado%')->sum('points');
    }

    public function test_compras_suma_un_pedido_pagado_y_al_llegar_a_la_meta_otorga_su_xp_una_sola_vez(): void
    {
        $reto = $this->reto('compras', 2, 80);

        $this->pagarPedido();
        $avance = $this->participacion($reto);
        $this->assertSame(1, $avance->current_count);
        $this->assertSame(50, $avance->progress);
        $this->assertSame('en_progreso', $avance->status);
        $this->assertSame(0, $this->xpDeRetos());

        $this->pagarPedido();
        $avance = $this->participacion($reto);
        $this->assertSame('completado', $avance->status);
        $this->assertSame(100, $avance->progress);
        $this->assertNotNull($avance->completed_at);
        $this->assertSame(80, $this->xpDeRetos());

        // Ya completado: otro pago no lo vuelve a contar ni da XP doble.
        $this->pagarPedido();
        $this->assertSame(2, $this->participacion($reto)->current_count);
        $this->assertSame(80, $this->xpDeRetos());
    }

    public function test_visitas_cuenta_una_sola_vez_por_dia_con_pago(): void
    {
        $reto = $this->reto('visitas', 3);

        $this->pagarPedido();
        $this->pagarPedido();   // mismo día: sigue siendo una visita
        $this->assertSame(1, $this->participacion($reto)->current_count);

        $this->travel(1)->days();
        $this->pagarPedido();
        $this->assertSame(2, $this->participacion($reto)->current_count);
    }

    public function test_gastronomico_suma_solo_los_productos_que_el_comensal_no_habia_probado(): void
    {
        $reto = $this->reto('gastronomico', 3, 120);
        $silpancho = $this->producto('Silpancho');
        $fricase = $this->producto('Fricasé');
        $sopa = $this->producto('Sopa de Maní');

        $this->pagarPedido([$silpancho, $fricase]);        // 2 platos nuevos
        $this->assertSame(2, $this->participacion($reto)->current_count);

        $this->pagarPedido([$silpancho]);                  // ya lo había probado
        $this->assertSame(2, $this->participacion($reto)->current_count);

        $this->pagarPedido([$fricase, $sopa]);             // solo la sopa es nueva
        $avance = $this->participacion($reto);
        $this->assertSame(3, $avance->current_count);
        $this->assertSame('completado', $avance->status);
        $this->assertSame(120, $this->xpDeRetos());
    }

    public function test_no_avanzan_los_retos_especiales_vencidos_inactivos_ni_los_no_aceptados(): void
    {
        $especial = $this->reto('especial', 1);
        $vencido = $this->reto('compras', 1, 50, ['end_date' => now()->subDay()]);
        $inactivo = $this->reto('compras', 1, 50, ['active' => false]);
        $sinAceptar = Challenge::create([
            'name' => 'No aceptado', 'xp_reward' => 50, 'goal' => 1, 'type' => 'compras', 'active' => true,
        ]);

        $this->pagarPedido();

        foreach ([$especial, $vencido, $inactivo] as $reto) {
            $this->assertSame(0, $this->participacion($reto)->current_count);
        }
        $this->assertFalse(UserChallenge::where('challenge_id', $sinAceptar->id)->exists());
        $this->assertSame(0, $this->xpDeRetos());
    }

    public function test_un_pago_por_qr_avanza_los_retos_recien_cuando_caja_lo_confirma(): void
    {
        $reto = $this->reto('compras', 5);
        $qr = PaymentMethod::create(['name' => 'QR', 'requires_confirmation' => true]);
        $pedido = Order::create([
            'user_id' => $this->comensal->id, 'table_id' => $this->mesa->id, 'status' => 'entregado', 'total' => 20,
        ]);

        $pago = $this->postJson('/api/payments', [
            'order_id' => $pedido->id, 'payment_method_id' => $qr->id, 'amount' => 20,
        ])->assertCreated()->json();
        $this->assertSame(0, $this->participacion($reto)->current_count);

        $this->patchJson("/api/payments/{$pago['id']}/confirm")->assertOk();
        $this->assertSame(1, $this->participacion($reto)->current_count);
    }
}
