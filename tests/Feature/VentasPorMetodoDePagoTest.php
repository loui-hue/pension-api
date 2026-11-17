<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Control de caja (RF-21): el reporte separa lo cobrado por método de pago, para
 * cuadrar el efectivo contra el QR. Cuenta solo los pagos completados, por la
 * fecha en que se cobraron.
 */
class VentasPorMetodoDePagoTest extends TestCase
{
    use RefreshDatabase;

    private Table $mesa;
    private User $comensal;
    private array $metodos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 13:00:00'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secreto123']);
        $admin->roles()->attach(Role::create(['name' => 'administrador']));
        Sanctum::actingAs($admin);

        $this->comensal = User::create(['name' => 'Ana', 'email' => 'ana@test.local', 'password' => 'secreto123']);
        $this->mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
        foreach (['Efectivo', 'QR', 'Transferencia', 'Tarjeta'] as $nombre) {
            $this->metodos[$nombre] = PaymentMethod::create(['name' => $nombre]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pago(string $metodo, float $monto, string $estado, ?string $cobradoEn = '2026-11-17 12:00:00'): Payment
    {
        $pedido = Order::create([
            'user_id' => $this->comensal->id, 'table_id' => $this->mesa->id,
            'status' => $estado === 'completado' ? 'pagado' : 'entregado', 'total' => $monto,
        ]);
        return Payment::create([
            'order_id' => $pedido->id, 'payment_method_id' => $this->metodos[$metodo]->id,
            'amount' => $monto, 'status' => $estado,
            'confirmed_at' => $estado === 'pendiente' ? null : $cobradoEn,
        ]);
    }

    private function reporte(): array
    {
        return $this->getJson('/api/reports/dashboard?from=2026-11-17&to=2026-11-17')
            ->assertOk()->json('payments_by_method');
    }

    public function test_los_pagos_completados_se_agrupan_por_metodo_con_monto_y_cantidad(): void
    {
        $this->pago('Efectivo', 29, 'completado');
        $this->pago('Efectivo', 15, 'completado');
        $this->pago('QR', 31, 'completado');

        $bloque = $this->reporte();
        $metodos = collect($bloque['methods'])->keyBy('name');

        $this->assertSame(['Efectivo', 'QR', 'Transferencia', 'Tarjeta'], collect($bloque['methods'])->pluck('name')->all());
        $this->assertSame(2, $metodos['Efectivo']['payments']);
        $this->assertEquals(44, $metodos['Efectivo']['amount']);
        $this->assertSame(1, $metodos['QR']['payments']);
        $this->assertEquals(31, $metodos['QR']['amount']);
        $this->assertSame(0, $metodos['Tarjeta']['payments']);
        $this->assertEquals(0, $metodos['Tarjeta']['amount']);
        $this->assertSame(3, $bloque['total_payments']);
        $this->assertEquals(75, $bloque['total_amount']);
    }

    public function test_los_pendientes_y_rechazados_no_suman(): void
    {
        $this->pago('Efectivo', 29, 'completado');
        $this->pago('QR', 31, 'pendiente');
        $this->pago('Transferencia', 50, 'rechazado');

        $bloque = $this->reporte();
        $metodos = collect($bloque['methods'])->keyBy('name');

        $this->assertEquals(29, $bloque['total_amount']);
        $this->assertSame(0, $metodos['QR']['payments']);
        $this->assertSame(0, $metodos['Transferencia']['payments']);
        // El QR que caja aún no confirma se informa aparte.
        $this->assertSame(1, $bloque['pending_confirmation']['payments']);
        $this->assertEquals(31, $bloque['pending_confirmation']['amount']);
    }

    public function test_cuenta_la_fecha_del_cobro_y_coincide_con_las_ventas_pagadas(): void
    {
        $this->pago('Efectivo', 29, 'completado');
        $this->pago('QR', 31, 'completado', '2026-11-17 12:30:00');
        $this->pago('Efectivo', 40, 'completado', '2026-11-16 20:00:00');   // cobrado ayer: otro periodo
        Order::where('total', 40)->update(['created_at' => '2026-11-16 19:00:00']);

        $respuesta = $this->getJson('/api/reports/dashboard?from=2026-11-17&to=2026-11-17')->assertOk();

        $this->assertEquals(60, $respuesta->json('payments_by_method.total_amount'));
        $this->assertEquals($respuesta->json('summary.sales'), $respuesta->json('payments_by_method.total_amount'));
    }
}
