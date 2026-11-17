<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los propietarios prefieren destacar al cliente frecuente y el único premio que
 * mencionan es una bebida de cortesía (entrevista del 20/10/2026). La insignia
 * "Cliente Frecuente" lo dice así; ninguna promete platos gratis ni descuentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('badges')->where('name', 'Cliente Frecuente')->update([
            'description' => 'Eres cliente frecuente de la pensión: la casa te invita un refresco del día',
        ]);
    }

    public function down(): void
    {
        DB::table('badges')->where('name', 'Cliente Frecuente')->update([
            'description' => 'Visitaste 10 veces la pensión',
        ]);
    }
};
