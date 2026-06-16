<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Table;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [
            ['number' => 1, 'capacity' => 4, 'status' => 'disponible'],
            ['number' => 2, 'capacity' => 4, 'status' => 'disponible'],
            ['number' => 3, 'capacity' => 6, 'status' => 'disponible'],
            ['number' => 4, 'capacity' => 6, 'status' => 'disponible'],
            ['number' => 5, 'capacity' => 2, 'status' => 'disponible'],
            ['number' => 6, 'capacity' => 8, 'status' => 'disponible'],
        ];

        foreach ($tables as $table) {
            Table::create($table);
        }
    }
}