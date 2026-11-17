<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Carta real de la Pensión Doña Roberta (atiende de lunes a sábado).
 * - Platos principales, bebidas y postres: todos los días.
 * - Menú del día: cambia según el día (day_of_week: 1 = lunes … 6 = sábado).
 * Los productos anteriores no se borran (sus pedidos siguen en el historial y
 * en los reportes): quedan como no disponibles. La categoría Entradas se desactiva.
 */
class MenuPensionSeeder extends Seeder
{
    /** Sopa y segundos del menú del día, de lunes (1) a viernes (5). */
    public const MENU_SEMANA = [
        1 => ['sopa' => 'Corbatines', 'segundos' => ['Chuleta', 'Tomatada de pollo']],
        2 => ['sopa' => 'Chairo', 'segundos' => ['Falso conejo', 'Pollo al horno']],
        3 => ['sopa' => 'Sopa de fideo', 'segundos' => ['Milanesa de pollo', 'Albóndiga']],
        4 => ['sopa' => 'Sopa de maní', 'segundos' => ['Pique', 'Pollo dorado']],
        5 => ['sopa' => 'Sopa de arroz con papa picada', 'segundos' => ['Silpancho', 'Guiso de pollo']],
    ];

    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado'];

    public function run(): void
    {
        $principales = $this->categoria('Platos principales', 'Se sirven todos los días, de lunes a sábado');
        $bebidas = $this->categoria('Bebidas', 'Jugos y refrescos');
        $postres = $this->categoria('Postres', 'Postres');
        $menu = $this->categoria('Menú del día', 'Sopa y segundo; cambia según el día');
        Category::where('name', 'Entradas')->update(['active' => false]);

        $carta = [
            [$principales, 'Silpancho', 'Plato principal', 14],
            [$principales, 'Asado', 'Plato principal', 16],
            [$principales, 'Chuleta', 'Plato principal', 16],

            [$bebidas, 'Jugo natural sabor Tumbo', 'Jugo natural del Sol', 15],
            [$bebidas, 'Jugo natural sabor Manzana', 'Jugo natural del Sol', 15],
            [$bebidas, 'Jugo natural sabor Durazno', 'Jugo natural del Sol', 15],
            [$bebidas, 'Jugo natural sabor Guinda', 'Jugo natural del Sol', 15],
            [$bebidas, 'Refresco CIN sabor Mocochinchi', 'Refresco CIN', 16],
            [$bebidas, 'Refresco del día (vaso)', 'Refresco del día en vaso', 3],
            [$bebidas, 'Refresco del día (jarra 1/2 L)', 'Jarra de medio litro', 8],

            [$postres, 'Gelatina', 'Postre', 3],
        ];
        foreach (self::MENU_SEMANA as $dia => $m) {
            foreach ($m['segundos'] as $segundo) {
                $carta[] = [$menu, "{$m['sopa']} + {$segundo}", 'Menú del día: sopa y segundo', 15, $dia];
            }
            $carta[] = [$menu, "Solo sopa: {$m['sopa']}", 'Solo la sopa del día', 7, $dia];
        }
        $carta[] = [$menu, 'Caldo de pollo', 'Menú del día del sábado', 12, 6];

        // Lo que no está en la carta nueva deja de ofrecerse (sin borrar el historial).
        Product::query()->update(['available' => false]);

        foreach ($carta as $item) {
            [$categoria, $nombre, $descripcion, $precio] = $item;
            Product::updateOrCreate(
                ['category_id' => $categoria->id, 'name' => $nombre, 'day_of_week' => $item[4] ?? null],
                ['description' => $descripcion, 'price' => $precio, 'available' => true]
            );
        }
    }

    private function categoria(string $nombre, string $descripcion): Category
    {
        return Category::updateOrCreate(['name' => $nombre], ['description' => $descripcion, 'active' => true]);
    }
}
