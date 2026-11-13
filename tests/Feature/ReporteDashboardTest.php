<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LoyaltyPoint;
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
 * Prueba de integración 3: reporte de indicadores del Dashboard.
 * Componentes integrados: ReportController + Order + OrderItem + LoyaltyPoint + base de datos.
 */
class ReporteDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConRol(string $rol, string $email): User
    {
        $usuario = User::create(['name' => "Usuario {$email}", 'email' => $email, 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    private function pedido(User $comensal, Table $mesa, Product $producto, int $cantidad, string $estado, string $fecha): Order
    {
        $total = $producto->price * $cantidad;
        $order = Order::create([
            'user_id' => $comensal->id,
            'table_id' => $mesa->id,
            'status' => $estado,
            'total' => $total,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $producto->id,
            'quantity' => $cantidad,
            'unit_price' => $producto->price,
            'subtotal' => $total,
        ]);
        $order->created_at = $fecha;
        $order->save();
        return $order;
    }

    public function test_reporte_calcula_ventas_productos_visitas_y_fidelizacion(): void
    {
        $admin = $this->usuarioConRol('administrador', 'admin@test.local');
        $ana = $this->usuarioConRol('cliente', 'ana@test.local');
        $luis = $this->usuarioConRol('cliente', 'luis@test.local');
        $this->usuarioConRol('cliente', 'sin-visitas@test.local');
        Sanctum::actingAs($admin);

        $mesa = Table::create(['number' => 1, 'capacity' => 4, 'status' => 'disponible']);
        $categoria = Category::create(['name' => 'Almuerzos']);
        $sopa = Product::create(['category_id' => $categoria->id, 'name' => 'Sopa de maní', 'price' => 15.00]);
        $refresco = Product::create(['category_id' => $categoria->id, 'name' => 'Refresco', 'price' => 8.00]);

        // Ana visita dos días distintos; Luis uno. Un pedido cancelado no cuenta como venta.
        $this->pedido($ana, $mesa, $sopa, 2, 'pagado', '2026-10-01 12:30:00');      // 30.00
        $this->pedido($ana, $mesa, $refresco, 1, 'pagado', '2026-10-02 13:00:00');  // 8.00
        $this->pedido($luis, $mesa, $sopa, 1, 'pagado', '2026-10-02 13:10:00');     // 15.00
        $this->pedido($luis, $mesa, $refresco, 5, 'cancelado', '2026-10-02 13:20:00');
        // Fuera del periodo: no debe contarse.
        $this->pedido($luis, $mesa, $sopa, 3, 'pagado', '2026-09-15 12:00:00');

        $xp = LoyaltyPoint::create(['user_id' => $ana->id, 'points' => 30, 'total_xp' => 30]);
        $xp->created_at = '2026-10-01 12:35:00';
        $xp->save();

        $respuesta = $this->getJson('/api/reports/dashboard?from=2026-10-01&to=2026-10-03');

        $respuesta->assertOk()
            ->assertJsonPath('summary.orders', 4)
            ->assertJsonPath('summary.paid_orders', 3)
            ->assertJsonPath('summary.cancelled_orders', 1)
            ->assertJsonPath('top_products.0.name', 'Sopa de maní')
            ->assertJsonPath('top_products.0.quantity', 3)
            ->assertJsonPath('visits.unique_diners', 2)
            ->assertJsonPath('visits.total_visits', 3)
            ->assertJsonPath('visits.frequent_diners.0.user_id', $ana->id)
            ->assertJsonPath('loyalty.registered_diners', 3)
            ->assertJsonPath('loyalty.participants', 1)
            ->assertJsonPath('loyalty.xp_awarded', 30);

        $this->assertEquals(53.00, $respuesta->json('summary.sales'));
        $this->assertEquals(17.67, $respuesta->json('summary.average_ticket'));
        $this->assertCount(3, $respuesta->json('sales_by_day'));
        $this->assertEquals(23.00, $respuesta->json('sales_by_day.1.sales'));
        $this->assertEquals(33.3, $respuesta->json('loyalty.participation_rate'));
    }

    public function test_solo_el_administrador_puede_ver_reportes(): void
    {
        Sanctum::actingAs($this->usuarioConRol('mesero', 'mesero@test.local'));

        $this->getJson('/api/reports/dashboard')->assertForbidden();
    }
}
