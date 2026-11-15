<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avance automático de los retos al pagar un pedido.
     * - challenges.goal: meta del reto (visitas, pedidos o platos nuevos).
     * - user_challenges.current_count: cuánto lleva el comensal hacia la meta.
     * - user_challenges.last_counted_on: último día contado (una visita por día).
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->unsignedInteger('goal')->default(1)->after('xp_reward');
        });
        Schema::table('user_challenges', function (Blueprint $table) {
            $table->unsignedInteger('current_count')->default(0)->after('progress');
            $table->date('last_counted_on')->nullable()->after('current_count');
        });

        // Metas de los retos que ya existen, según lo que dice su descripción.
        DB::table('challenges')->where('name', 'Semana Completa')->update(['goal' => 5]);
        DB::table('challenges')->where('name', 'Explorador Gastronómico')->update(['goal' => 5]);
        DB::table('challenges')->where('name', 'Gran Comprador')->update(['goal' => 10]);
        // Responder preguntas no depende de los pagos: sigue siendo un reto manual.
        DB::table('challenges')->where('name', 'Sabio de la Pensión')
            ->update(['goal' => 10, 'type' => 'especial']);
    }

    public function down(): void
    {
        Schema::table('user_challenges', function (Blueprint $table) {
            $table->dropColumn(['current_count', 'last_counted_on']);
        });
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn('goal');
        });
    }
};
