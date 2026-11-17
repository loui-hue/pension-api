<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\LoyaltyPoint;
use App\Models\Role;
use App\Models\User;
use App\Models\UserChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pantalla "Gestionar retos" (RF-20): el administrador crea retos y registra en
 * persona el avance de los retos especiales, una unidad por vez.
 */
class GestionarRetosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $comensal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario('administrador');
        $this->comensal = $this->usuario('cliente');
    }

    private function usuario(string $rol): User
    {
        $usuario = User::create(['name' => ucfirst($rol), 'email' => "$rol@test.local", 'password' => 'secreto123']);
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
        return $usuario;
    }

    private function reto(string $tipo, int $meta = 3, int $xp = 250): Challenge
    {
        return Challenge::create([
            'name' => "Reto $tipo", 'xp_reward' => $xp, 'goal' => $meta, 'type' => $tipo,
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(30), 'active' => true,
        ]);
    }

    private function unir(Challenge $reto): UserChallenge
    {
        return UserChallenge::create([
            'user_id' => $this->comensal->id, 'challenge_id' => $reto->id, 'status' => 'en_progreso', 'progress' => 0,
        ]);
    }

    private function xpDeRetos(): int
    {
        return (int) LoyaltyPoint::where('user_id', $this->comensal->id)
            ->where('reason', 'like', 'Reto completado%')->sum('points');
    }

    public function test_solo_el_administrador_crea_retos_y_registra_avance(): void
    {
        $nuevo = [
            'name' => 'Cliente Recomendador', 'description' => 'Trae a 3 amigos nuevos a la pensión',
            'type' => 'especial', 'goal' => 3, 'xp_reward' => 250,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'active' => true,
        ];
        $participacion = $this->unir($this->reto('especial'));

        foreach (['mesero', 'caja', 'cocina', 'cliente'] as $rol) {
            Sanctum::actingAs($rol === 'cliente' ? $this->comensal : $this->usuario($rol));
            $this->postJson('/api/challenges', $nuevo)->assertForbidden();
            $this->getJson('/api/challenges/gestion')->assertForbidden();
            $this->getJson("/api/challenges/{$participacion->challenge_id}/participantes")->assertForbidden();
            $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertForbidden();
        }
        $this->assertSame(0, $participacion->fresh()->current_count);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/challenges', $nuevo)->assertCreated()->assertJsonPath('goal', 3);
        $this->getJson('/api/challenges/gestion')->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.participantes', 1);
    }

    public function test_el_registro_suma_una_unidad_y_completa_el_reto_al_llegar_a_la_meta(): void
    {
        $reto = $this->reto('especial', 3, 250);
        $participacion = $this->unir($reto);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertOk()
            ->assertJsonPath('current_count', 1)
            ->assertJsonPath('progress', 33)
            ->assertJsonPath('status', 'en_progreso');
        $this->getJson("/api/challenges/{$reto->id}/participantes")->assertOk()
            ->assertJsonPath('participantes.0.user.name', 'Cliente')
            ->assertJsonPath('participantes.0.current_count', 1)
            ->assertJsonPath('participantes.0.goal', 3);
        $this->assertSame(0, $this->xpDeRetos());

        $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertOk();
        $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertOk()
            ->assertJsonPath('current_count', 3)
            ->assertJsonPath('status', 'completado')
            ->assertJsonPath('progress', 100);
        $this->assertSame(250, $this->xpDeRetos());

        // Ya completado: otro registro se rechaza y no da XP doble.
        $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertStatus(422);
        $this->assertSame(3, $participacion->fresh()->current_count);
        $this->assertSame(250, $this->xpDeRetos());
    }

    public function test_un_reto_automatico_no_acepta_el_registro_manual(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['visitas', 'compras', 'gastronomico'] as $tipo) {
            $participacion = $this->unir($this->reto($tipo, 1));

            $this->patchJson("/api/challenges/{$participacion->id}/progress")->assertStatus(422)
                ->assertJsonPath('message', "El reto «Reto {$tipo}» avanza solo al cobrar los pedidos: no se registra a mano");
            $this->assertSame(0, $participacion->fresh()->current_count);
            $this->assertSame('en_progreso', $participacion->fresh()->status);
        }
        $this->assertSame(0, $this->xpDeRetos());
    }
}
