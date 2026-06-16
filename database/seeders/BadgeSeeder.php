<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Badge;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['name' => 'Primer Pedido', 'description' => 'Realizaste tu primer pedido', 'xp_required' => 0, 'type' => 'compras'],
            ['name' => 'Cliente Frecuente', 'description' => 'Visitaste 10 veces la pensión', 'xp_required' => 100, 'type' => 'visitas'],
            ['name' => 'Racha de Fuego', 'description' => 'Visitaste 7 días seguidos', 'xp_required' => 200, 'type' => 'visitas'],
            ['name' => 'Gourmet', 'description' => 'Probaste todos los platos del menú', 'xp_required' => 300, 'type' => 'compras'],
            ['name' => 'Retador', 'description' => 'Completaste tu primer reto', 'xp_required' => 150, 'type' => 'retos'],
            ['name' => 'VIP', 'description' => 'Cliente especial de la pensión', 'xp_required' => 500, 'type' => 'especial'],
        ];

        foreach ($badges as $badge) {
            Badge::create($badge);
        }
    }
}