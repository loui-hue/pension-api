<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cortesía de una insignia se entrega de verdad y una sola vez:
 * - badges.reward_product_id: el producto que la casa invita al obtenerla
 *   ("Cliente Frecuente" → "Refresco del día (vaso)", el único premio que los
 *   propietarios mencionan, entrevista del 20/10/2026).
 * - order_items.courtesy_badge_id: el plato del pedido que se dio como cortesía
 *   (precio 0). La cortesía está usada si un pedido no cancelado del comensal la
 *   incluye; si el pedido se cancela o se quita, vuelve a estar disponible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->foreignId('reward_product_id')->nullable()->after('type')->constrained('products')->nullOnDelete();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('courtesy_badge_id')->nullable()->after('notes')->constrained('badges')->nullOnDelete();
        });

        // En el portal los datos llegan con la copia de la pensión.
        if (config('portal.modo') === 'portal') {
            return;
        }
        $refresco = DB::table('products')->where('name', 'Refresco del día (vaso)')->value('id');
        DB::table('badges')->where('name', 'Cliente Frecuente')->update([
            'reward_product_id' => $refresco,
            'description' => 'Alcanzaste 100 XP: la casa te invita un refresco del día (vaso), una vez',
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courtesy_badge_id');
        });
        Schema::table('badges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_product_id');
        });
        if (config('portal.modo') !== 'portal') {
            DB::table('badges')->where('name', 'Cliente Frecuente')->update([
                'description' => 'Alcanzaste 100 XP: la casa te invita un refresco del día',
            ]);
        }
    }
};
