<?php

namespace Tests\Feature;

use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de integración 4: cobro por QR fijo / transferencia con confirmación manual.
 * Componentes integrados: PaymentController + LoyaltyService + Order + Table + Payment + base de datos.
 */
class PagoConfirmacionManualTest extends TestCase
{
    use RefreshDatabase;

    private User $comensal;
    private Order $pedido;
    private Table $mesa;
    private PaymentMethod $qr;
    private PaymentMethod $efectivo;

    private function usuarioConRol(string $rol, string $email): User
    {
        $usuario = User::create(['name' => ucfirst($rol) . ' de prueba', 'email' => $email, 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->comensal = $this->usuarioConRol('cliente', 'comensal@test.local');
        $this->mesa = Table::create(['number' => 4, 'capacity' => 4, 'status' => 'ocupada']);
        $this->pedido = Order::create([
            'user_id' => $this->comensal->id,
            'table_id' => $this->mesa->id,
            'status' => 'entregado',
            'total' => 32.00,
        ]);
        $this->qr = PaymentMethod::create(['name' => 'QR', 'requires_confirmation' => true]);
        $this->efectivo = PaymentMethod::create(['name' => 'Efectivo']);

        Sanctum::actingAs($this->usuarioConRol('caja', 'caja@test.local'));
    }

    private function cobrarConQr()
    {
        return $this->postJson('/api/payments', [
            'order_id' => $this->pedido->id,
            'payment_method_id' => $this->qr->id,
            'amount' => 32.00,
        ]);
    }

    private function xpDelComensal(): int
    {
        return (int) LoyaltyPoint::where('user_id', $this->comensal->id)->sum('points');
    }

    public function test_pago_qr_queda_pendiente_sin_xp_y_con_plazo(): void
    {
        $respuesta = $this->cobrarConQr();

        $respuesta->assertCreated()->assertJsonPath('status', 'pendiente');
        $this->assertGreaterThan(590, $respuesta->json('seconds_left'));   // ~10 minutos

        // Hasta que caja confirme: el pedido no está pagado, la mesa sigue ocupada y no hay XP.
        $this->assertSame('entregado', $this->pedido->fresh()->status);
        $this->assertSame('ocupada', $this->mesa->fresh()->status);
        $this->assertSame(0, $this->xpDelComensal());

        // No se puede iniciar un segundo cobro mientras hay uno por confirmar.
        $this->postJson('/api/payments', [
            'order_id' => $this->pedido->id,
            'payment_method_id' => $this->efectivo->id,
            'amount' => 32.00,
        ])->assertStatus(422);
    }

    public function test_confirmar_pago_qr_completa_pedido_y_acredita_xp(): void
    {
        $pagoId = $this->cobrarConQr()->json('id');

        $this->patchJson("/api/payments/{$pagoId}/confirm", ['reference' => 'OP-778812'])
            ->assertOk()
            ->assertJsonPath('status', 'completado')
            ->assertJsonPath('reference', 'OP-778812');

        $this->assertSame('pagado', $this->pedido->fresh()->status);
        $this->assertSame('disponible', $this->mesa->fresh()->status);
        $this->assertSame(32, $this->xpDelComensal());
        $this->assertNotNull(Payment::find($pagoId)->confirmed_by);

        // Confirmar dos veces no duplica el XP.
        $this->patchJson("/api/payments/{$pagoId}/confirm")->assertStatus(422);
        $this->assertSame(32, $this->xpDelComensal());
    }

    public function test_pago_vencido_aun_se_puede_confirmar(): void
    {
        $pagoId = $this->cobrarConQr()->json('id');
        $this->travel(15)->minutes();

        $this->getJson('/api/payments?status=pendiente')
            ->assertOk()
            ->assertJsonPath('0.seconds_left', 0);

        $this->patchJson("/api/payments/{$pagoId}/confirm")->assertOk();
        $this->assertSame('pagado', $this->pedido->fresh()->status);
    }

    public function test_rechazar_pago_qr_permite_cobrar_de_otra_forma(): void
    {
        $pagoId = $this->cobrarConQr()->json('id');

        $this->patchJson("/api/payments/{$pagoId}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rechazado');
        $this->assertSame('entregado', $this->pedido->fresh()->status);
        $this->assertSame(0, $this->xpDelComensal());

        // El comensal paga en efectivo: se completa en el acto.
        $this->postJson('/api/payments', [
            'order_id' => $this->pedido->id,
            'payment_method_id' => $this->efectivo->id,
            'amount' => 32.00,
        ])->assertCreated()->assertJsonPath('status', 'completado');

        $this->assertSame('pagado', $this->pedido->fresh()->status);
        $this->assertSame(32, $this->xpDelComensal());
    }

    public function test_mesero_no_puede_confirmar_pagos(): void
    {
        $pagoId = $this->cobrarConQr()->json('id');
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));

        $this->patchJson("/api/payments/{$pagoId}/confirm")->assertForbidden();
        $this->assertSame('pendiente', Payment::find($pagoId)->status);
    }

    public function test_admin_carga_qr_y_datos_de_cuenta(): void
    {
        Storage::fake();
        Sanctum::actingAs($this->usuarioConRol('administrador', 'admin@test.local'));

        $this->post("/api/payment-methods/{$this->qr->id}", [
            'bank_name' => 'Banco de prueba',
            'account_number' => '1000-2000-3000',
            'account_holder' => 'Roberta Mamani',
            'qr_image' => UploadedFile::fake()->create('qr.png', 120, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('has_qr', true)
            ->assertJsonPath('account_holder', 'Roberta Mamani');

        Storage::assertExists($this->qr->fresh()->qr_image_path);
        $this->get("/api/payment-methods/{$this->qr->id}/qr")->assertOk();
    }
}
