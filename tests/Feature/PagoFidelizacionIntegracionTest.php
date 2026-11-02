<?php

namespace Tests\Feature;

use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Streak;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de integración 2: cobro de un pedido y fidelización automática.
 * Componentes integrados: PaymentController + LoyaltyService + Order + LoyaltyPoint + Streak + base de datos.
 */
class PagoFidelizacionIntegracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagar_pedido_marca_pagado_y_acredita_xp_y_racha_al_comensal(): void
    {
        // Arrange: un comensal con un pedido entregado de 45.50 Bs y un cajero autenticado.
        $comensal = User::create([
            'name' => 'Comensal de prueba',
            'email' => 'comensal@test.local',
            'password' => 'secreto123',
        ]);
        $cajero = User::create([
            'name' => 'Cajero de prueba',
            'email' => 'cajero@test.local',
            'password' => 'secreto123',
        ]);
        Sanctum::actingAs($cajero);

        $mesa = Table::create(['number' => 5, 'capacity' => 2, 'status' => 'ocupada']);
        $pedido = Order::create([
            'user_id' => $comensal->id,
            'table_id' => $mesa->id,
            'status' => 'entregado',
            'total' => 45.50,
        ]);
        $efectivo = PaymentMethod::create(['name' => 'Efectivo']);

        // Act: se registra el pago del pedido en la API.
        $respuesta = $this->postJson('/api/payments', [
            'order_id' => $pedido->id,
            'payment_method_id' => $efectivo->id,
            'amount' => 45.50,
        ]);

        // Assert: pago completado, pedido pagado, XP acreditado (redondeo de 45.50 = 46) y racha iniciada.
        $respuesta->assertCreated()->assertJsonPath('status', 'completado');
        $this->assertSame('pagado', $pedido->fresh()->status);

        $xp = LoyaltyPoint::where('user_id', $comensal->id)->sum('points');
        $this->assertEquals(46, $xp);

        $racha = Streak::where('user_id', $comensal->id)->firstOrFail();
        $this->assertEquals(1, $racha->current_streak);
        $this->assertTrue($racha->last_visit->isToday());

        // La mesa queda libre y el mismo pedido no se puede cobrar dos veces (ni dar XP doble).
        $this->assertSame('disponible', $mesa->fresh()->status);
        $this->postJson('/api/payments', [
            'order_id' => $pedido->id,
            'payment_method_id' => $efectivo->id,
            'amount' => 45.50,
        ])->assertStatus(422);
        $this->assertEquals(46, LoyaltyPoint::where('user_id', $comensal->id)->sum('points'));
    }
}
