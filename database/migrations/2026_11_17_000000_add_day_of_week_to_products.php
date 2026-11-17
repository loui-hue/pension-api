<?php

use Database\Seeders\MenuPensionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menú del día según el día: day_of_week = 1 (lunes) … 6 (sábado); null = todos los días.
     * Además carga la carta real de la pensión (MenuPensionSeeder).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_of_week')->nullable()->after('available');
        });

        // El portal en la nube no carga la carta: la recibe en la copia de la pensión.
        if (config('portal.modo') !== 'portal' && \App\Models\Category::exists()) {
            (new MenuPensionSeeder())->run();
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('day_of_week');
        });
    }
};
