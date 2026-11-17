<?php

use Database\Seeders\TableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La pensión tiene 10 mesas (entrevista con los propietarios). Agrega de la 7 a la 10
 * sin tocar las existentes ni los pedidos. En el portal no hace falta: las mesas
 * llegan con la copia de la pensión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('portal.modo') === 'portal' || !DB::table('tables')->exists()) {
            return;
        }
        (new TableSeeder())->run();
    }

    public function down(): void
    {
        // Las mesas 7 a 10 pueden tener pedidos asociados: no se borran.
    }
};
