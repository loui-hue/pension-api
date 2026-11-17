<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El reto de visitas suma un día con pago aunque no sean seguidos (los días
     * seguidos ya los premia la racha): la descripción ahora lo dice así.
     */
    public function up(): void
    {
        DB::table('challenges')->where('name', 'Semana Completa')
            ->update(['description' => 'Visita la pensión 5 días distintos']);
    }

    public function down(): void
    {
        DB::table('challenges')->where('name', 'Semana Completa')
            ->update(['description' => 'Visita la pensión 5 días seguidos']);
    }
};
