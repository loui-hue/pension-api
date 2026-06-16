<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            TableSeeder::class,
            PaymentMethodSeeder::class,
            BadgeSeeder::class,
            ChallengeSeeder::class,
        ]);
    }
}