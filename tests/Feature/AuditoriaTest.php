<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registro de auditoría: quién hizo qué y cuándo. Se guarda después de cada acción
 * que cambia datos, nunca guarda contraseñas, solo lo ve el administrador y, si
 * guardar el registro fallara, la acción del usuario se realiza igual.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private Product $sopa;
    private Table $mesa;
    private User $comensal;

    protected function setUp(): void
    {
        parent::setUp();
        $categoria = Category::create(['name' => 'Almuerzos']);
        $this->sopa = Product::create(['category_id' => $categoria->id, 'name' => 'Sopa de maní', 'price' => 12]);
        $this->mesa = Table::create(['number' => 7, 'capacity' => 4, 'status' => 'disponible']);
        $this->comensal = $this->usuario('cliente', 'Ana Comensal');
    }

    private function usuario(string $rol, string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => strtolower(str_replace(' ', '.', $nombre)) . '@test.local', 'password' => 'secreto123']);
        $u->roles()->attach(Role::firstOrCreate(['name' => $rol])->id);
        return $u;
    }

    private function ultimo(): AuditLog
    {
        return AuditLog::latest('id')->firstOrFail();
    }

    public function test_registra_inicios_de_sesion_exitosos_fallidos_y_bloqueados_sin_guardar_la_contrasena(): void
    {
        $this->postJson('/api/login', ['email' => 'ana.comensal@test.local', 'password' => 'secreto123'])->assertOk();
        $this->assertSame('sesion.inicio', $this->ultimo()->action);
        $this->assertSame('Ana Comensal', $this->ultimo()->user_name);
        $this->assertSame('cliente', $this->ultimo()->user_role);

        $this->postJson('/api/login', ['email' => 'ana.comensal@test.local', 'password' => 'clave-equivocada'])->assertUnauthorized();
        $this->assertSame('sesion.fallida', $this->ultimo()->action);
        $this->assertSame('Intento fallido de inicio de sesión con «ana.comensal@test.local»', $this->ultimo()->description);
        $this->assertSame($this->comensal->id, $this->ultimo()->user_id);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ana.comensal@test.local', 'password' => 'otra-mala']);
        }
        $this->assertSame('sesion.bloqueada', $this->ultimo()->action);

        // Ninguna contraseña queda guardada en el registro.
        $todo = AuditLog::all()->toJson();
        foreach (['secreto123', 'clave-equivocada', 'otra-mala'] as $password) {
            $this->assertStringNotContainsString($password, $todo);
        }
    }

    public function test_registra_quien_tomo_el_pedido_y_quien_lo_cobro(): void
    {
        Sanctum::actingAs($this->usuario('mesero', 'Juan Mesero'));
        $id = $this->postJson('/api/orders', [
            'table_id' => $this->mesa->id, 'user_id' => $this->comensal->id,
            'items' => [['product_id' => $this->sopa->id, 'quantity' => 2]],
        ])->assertCreated()->json('id');

        $registro = $this->ultimo();
        $this->assertSame('pedido.creado', $registro->action);
        $this->assertSame('Juan Mesero', $registro->user_name);
        $this->assertSame("Registró el pedido #{$id} de Ana Comensal, mesa 7, Bs. 24.00", $registro->description);
        $this->assertSame('pedido', $registro->subject_type);
        $this->assertSame($id, $registro->subject_id);

        Order::whereKey($id)->update(['status' => 'entregado']);
        $efectivo = PaymentMethod::create(['name' => 'Efectivo', 'requires_confirmation' => false, 'active' => true]);
        Sanctum::actingAs($this->usuario('caja', 'Pedro Caja'));
        $this->postJson('/api/payments', ['order_id' => $id, 'payment_method_id' => $efectivo->id, 'amount' => 24])->assertCreated();

        $this->assertSame('cobro.registrado', $this->ultimo()->action);
        $this->assertSame('Pedro Caja', $this->ultimo()->user_name);
        $this->assertSame("Cobró el pedido #{$id}: Bs. 24.00 en Efectivo", $this->ultimo()->description);
    }

    public function test_registra_la_gestion_del_administrador(): void
    {
        Sanctum::actingAs($this->usuario('administrador', 'Doña Roberta'));

        $this->putJson("/api/products/{$this->sopa->id}", ['available' => false])->assertOk();
        $this->assertSame('Marcó «Sopa de maní» como no disponible', $this->ultimo()->description);

        $this->postJson('/api/tables', ['number' => 11, 'capacity' => 6])->assertCreated();
        $this->assertSame('gestion.mesa', $this->ultimo()->action);
        $this->assertSame('Agregó la mesa 11 (6 personas)', $this->ultimo()->description);
    }

    public function test_las_consultas_y_las_acciones_rechazadas_no_se_registran(): void
    {
        Sanctum::actingAs($this->usuario('administrador', 'Doña Roberta'));
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/orders')->assertOk();
        $this->postJson('/api/tables', ['number' => 7, 'capacity' => 4])->assertStatus(422);   // número repetido

        $this->assertSame(0, AuditLog::count());
    }

    public function test_solo_el_administrador_consulta_la_auditoria_y_puede_filtrarla(): void
    {
        $pedido = Order::create(['user_id' => $this->comensal->id, 'table_id' => $this->mesa->id, 'status' => 'pendiente', 'total' => 12]);
        OrderItem::create(['order_id' => $pedido->id, 'product_id' => $this->sopa->id, 'quantity' => 1, 'unit_price' => 12, 'subtotal' => 12]);
        Sanctum::actingAs($this->usuario('mesero', 'Juan Mesero'));
        $this->patchJson("/api/orders/{$pedido->id}/status", ['status' => 'cancelado'])->assertOk();
        $this->postJson('/api/logout');

        foreach (['mesero', 'cocina', 'caja', 'cliente'] as $rol) {
            Sanctum::actingAs($this->usuario($rol, "Otro {$rol}"));
            $this->getJson('/api/auditoria')->assertForbidden();
        }

        Sanctum::actingAs($this->usuario('administrador', 'Doña Roberta'));
        $todo = $this->getJson('/api/auditoria')->assertOk()
            ->assertJsonPath('categorias.cobro', 'Cobros')
            ->assertJsonPath('pagina', 1);
        $this->assertSame('sesion.cierre', $todo->json('registros.0.action'));   // lo más reciente primero

        $pedidos = $this->getJson('/api/auditoria?categoria=pedido')->assertOk();
        $this->assertSame(1, $pedidos->json('total'));
        $this->assertSame("Canceló el pedido #{$pedido->id}", $pedidos->json('registros.0.description'));
        $this->assertSame('Juan Mesero', $pedidos->json('registros.0.user_name'));

        $this->getJson('/api/auditoria?categoria=inventada')->assertStatus(422);
    }

    public function test_si_no_se_puede_guardar_el_registro_la_accion_se_realiza_igual(): void
    {
        Schema::drop('audit_logs');
        Sanctum::actingAs($this->usuario('administrador', 'Doña Roberta'));

        $this->postJson('/api/categories', ['name' => 'Postres'])->assertCreated();
        $this->assertDatabaseHas('categories', ['name' => 'Postres']);
    }
}
