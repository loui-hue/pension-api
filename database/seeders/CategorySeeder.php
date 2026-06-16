<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Platos principales', 'description' => 'Almuerzos y cenas del día', 'active' => true],
            ['name' => 'Entradas', 'description' => 'Sopas y ensaladas', 'active' => true],
            ['name' => 'Bebidas', 'description' => 'Jugos, refrescos y aguas', 'active' => true],
            ['name' => 'Postres', 'description' => 'Dulces y postres del día', 'active' => true],
            ['name' => 'Menú del día', 'description' => 'Menú completo a precio especial', 'active' => true],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}