<?php

use Database\Seeders\BadgeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coherencia con la tesis (3.7 y RF-17):
 * - Las insignias se ganan solo por XP: su descripción dice el hito real de XP
 *   (antes prometían rachas, platos o retos que no se verificaban).
 * - El reto "Sabio de la Pensión" pedía responder preguntas que la app no tiene; se
 *   reemplaza por "Cliente Recomendador", que el personal verifica en persona. Se
 *   actualiza la misma fila, así se conservan las participaciones existentes.
 * En el portal no hace falta: insignias y retos llegan con la copia de la pensión.
 */
return new class extends Migration
{
    private const ANTES = [
        'Primer Pedido' => 'Realizaste tu primer pedido',
        'Cliente Frecuente' => 'Eres cliente frecuente de la pensión: la casa te invita un refresco del día',
        'Retador' => 'Completaste tu primer reto',
        'Racha de Fuego' => 'Visitaste 7 días seguidos',
        'Gourmet' => 'Probaste todos los platos del menú',
        'VIP' => 'Cliente especial de la pensión',
    ];

    public function up(): void
    {
        if (config('portal.modo') === 'portal') {
            return;
        }
        foreach (BadgeSeeder::INSIGNIAS as $insignia) {
            DB::table('badges')->where('name', $insignia['name'])->update(['description' => $insignia['description']]);
        }
        DB::table('challenges')->where('name', 'Sabio de la Pensión')->update([
            'name' => 'Cliente Recomendador',
            'description' => 'Trae a 3 amigos nuevos a la pensión',
            'goal' => 3,
            'type' => 'especial',
        ]);
    }

    public function down(): void
    {
        if (config('portal.modo') === 'portal') {
            return;
        }
        foreach (self::ANTES as $nombre => $descripcion) {
            DB::table('badges')->where('name', $nombre)->update(['description' => $descripcion]);
        }
        DB::table('challenges')->where('name', 'Cliente Recomendador')->update([
            'name' => 'Sabio de la Pensión',
            'description' => 'Responde 10 preguntas gastronómicas correctamente',
            'goal' => 10,
        ]);
    }
};
