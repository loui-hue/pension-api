<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Las bebidas se nombran por sabor: "Jugo natural sabor Tumbo", "Refresco CIN sabor Mocochinchi". */
    private const NOMBRES = [
        'Jugo del Sol de tumbo' => 'Jugo natural sabor Tumbo',
        'Jugo del Sol de manzana' => 'Jugo natural sabor Manzana',
        'Jugo del Sol de durazno' => 'Jugo natural sabor Durazno',
        'Jugo del Sol de guinda' => 'Jugo natural sabor Guinda',
        'Refresco CIN de mocochinchi' => 'Refresco CIN sabor Mocochinchi',
    ];

    public function up(): void
    {
        foreach (self::NOMBRES as $antes => $ahora) {
            DB::table('products')->where('name', $antes)->update(['name' => $ahora]);
        }
    }

    public function down(): void
    {
        foreach (self::NOMBRES as $antes => $ahora) {
            DB::table('products')->where('name', $ahora)->update(['name' => $antes]);
        }
    }
};
