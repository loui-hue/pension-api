<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $principal = Category::where('name', 'Platos principales')->first()->id;
        $entradas = Category::where('name', 'Entradas')->first()->id;
        $bebidas = Category::where('name', 'Bebidas')->first()->id;
        $postres = Category::where('name', 'Postres')->first()->id;
        $menu = Category::where('name', 'Menú del día')->first()->id;

        $products = [
            ['name' => 'Silpancho', 'description' => 'Silpancho cochabambino con arroz y papa', 'price' => 15.00, 'available' => true, 'category_id' => $principal],
            ['name' => 'Pique Macho', 'description' => 'Pique macho tradicional', 'price' => 20.00, 'available' => true, 'category_id' => $principal],
            ['name' => 'Fricasé', 'description' => 'Fricasé de cerdo con chuño', 'price' => 28.00, 'available' => true, 'category_id' => $principal],
            ['name' => 'Sopa de Maní', 'description' => 'Sopa de maní con fideos', 'price' => 10.00, 'available' => true, 'category_id' => $entradas],
            ['name' => 'Caldo de Pollo', 'description' => 'Caldo de pollo con verduras', 'price' => 10.00, 'available' => true, 'category_id' => $entradas],
            ['name' => 'Jugo de Naranja', 'description' => 'Jugo natural el sol', 'price' => 15.00, 'available' => true, 'category_id' => $bebidas],
            ['name' => 'Refresco del día', 'description' => 'Refresco natural del día', 'price' => 6.00, 'available' => true, 'category_id' => $bebidas],
            ['name' => 'Gelatina', 'description' => 'Gelatina', 'price' => 3.00, 'available' => true, 'category_id' => $postres],
            ['name' => 'Menú completo', 'description' => 'Sopa + segundo + refresco', 'price' => 15.00, 'available' => true, 'category_id' => $menu],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}