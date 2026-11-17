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
                'name' => 'Sabio de la Pensión',
                'description' => 'Responde 10 preguntas gastronómicas correctamente',
                'xp_reward' => 250,
                'goal' => 10,
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