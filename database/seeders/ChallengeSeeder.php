<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Challenge;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $challenges = [
            [
                'name' => 'Semana Completa',
                'description' => 'Visita la pensión 5 días distintos',
                'xp_reward' => 100,
                'goal' => 5,
                'type' => 'visitas',
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'active' => true,
            ],
            [
                'name' => 'Explorador Gastronómico',
                'description' => 'Prueba 5 platos diferentes',
                'xp_reward' => 150,
                'goal' => 5,
                'type' => 'gastronomico',
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'active' => true,
            ],
            [
                'name' => 'Gran Comprador',
                'description' => 'Realiza 10 pedidos en el mes',
                'xp_reward' => 200,
                'goal' => 10,
                'type' => 'compras',
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'active' => true,
            ],
            [
                // Reto especial: el personal lo verifica en persona y el administrador
                // registra el avance (PATCH /challenges/{id}/progress).
                'name' => 'Cliente Recomendador',
                'description' => 'Trae a 3 amigos nuevos a la pensión',
                'xp_reward' => 250,
                'goal' => 3,
                'type' => 'especial',
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'active' => true,
            ],
        ];

        foreach ($challenges as $challenge) {
            Challenge::create($challenge);
        }
    }
}