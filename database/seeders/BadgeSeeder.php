<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Badge;

class BadgeSeeder extends Seeder
{
    /**
     * Las insignias se otorgan solo al llegar a su XP (LoyaltyService::checkAndAwardBadges):
     * cada descripción dice ese hito real. "Primer Pedido" (0 XP) llega con el primer pago.
     */
    public const INSIGNIAS = [
        ['name' => 'Primer Pedido', 'description' => 'Pagaste tu primer pedido en la pensión', 'xp_required' => 0, 'type' => 'compras'],
        ['name' => 'Cliente Frecuente', 'description' => 'Alcanzaste 100 XP: la casa te invita un refresco del día', 'xp_required' => 100, 'type' => 'visitas'],
        ['name' => 'Retador', 'description' => 'Alcanzaste 150 XP en la pensión', 'xp_required' => 150, 'type' => 'retos'],
        ['name' => 'Racha de Fuego', 'description' => 'Alcanzaste 200 XP en la pensión', 'xp_required' => 200, 'type' => 'visitas'],
        ['name' => 'Gourmet', 'description' => 'Alcanzaste 300 XP en la pensión', 'xp_required' => 300, 'type' => 'compras'],
        ['name' => 'VIP', 'description' => 'Alcanzaste 500 XP: eres cliente VIP de la pensión', 'xp_required' => 500, 'type' => 'especial'],
    ];

    public function run(): void
    {
        foreach (self::INSIGNIAS as $badge) {
            Badge::create($badge);
        }
    }
}
