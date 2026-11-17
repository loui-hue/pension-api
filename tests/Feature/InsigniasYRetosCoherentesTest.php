<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\ChallengeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que la app promete debe ser lo que verifica (tesis 3.7, RF-17): las insignias
 * se ganan solo por XP y los retos especiales los comprueba el personal en persona.
 */
class InsigniasYRetosCoherentesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_insignia_dice_el_xp_con_el_que_se_gana(): void
    {
        (new BadgeSeeder())->run();

        foreach (Badge::where('xp_required', '>', 0)->get() as $insignia) {
            $this->assertStringContainsString("{$insignia->xp_required} XP", $insignia->description, $insignia->name);
        }
        $this->assertSame('Pagaste tu primer pedido en la pensión', Badge::where('name', 'Primer Pedido')->value('description'));
        $this->assertStringContainsString('refresco del día', Badge::where('name', 'Cliente Frecuente')->value('description'));
    }

    public function test_el_reto_especial_se_puede_verificar_en_persona(): void
    {
        (new ChallengeSeeder())->run();

        $this->assertFalse(Challenge::where('name', 'Sabio de la Pensión')->exists());
        $reto = Challenge::where('type', 'especial')->sole();
        $this->assertSame('Cliente Recomendador', $reto->name);
        $this->assertSame('Trae a 3 amigos nuevos a la pensión', $reto->description);
        $this->assertSame(3, (int) $reto->goal);
        $this->assertSame(250, (int) $reto->xp_reward);
    }
}
