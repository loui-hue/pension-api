<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Table;

class TableSeeder extends Seeder
{
    /** Las 10 mesas de la pensión (entrevista con los propietarios, 20/10/2026). */
    public const MESAS = [
        1 => 4, 2 => 4, 3 => 6, 4 => 6, 5 => 2,
        6 => 8, 7 => 4, 8 => 4, 9 => 2, 10 => 6,
    ];

    public function run(): void
    {
        // Idempotente: crea las que falten y no toca el estado de las que ya existen.
        foreach (self::MESAS as $numero => $capacidad) {
            Table::firstOrCreate(['number' => $numero], ['capacity' => $capacidad, 'status' => 'disponible']);
        }
    }
}
