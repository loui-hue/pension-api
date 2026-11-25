<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cortesías de las insignias: lo que la casa invita al comensal al obtener una
 * insignia con premio ("Cliente Frecuente" → un refresco del día), una sola vez.
 *
 * No hay un "vale" aparte que se pueda desincronizar: la cortesía está usada si un
 * pedido no cancelado del comensal la incluye. Si ese pedido se cancela, se borra o
 * se le quita el refresco, la cortesía vuelve a estar disponible sola.
 */
class Cortesias
{
    /**
     * Estado de cada cortesía del comensal (de las insignias con premio que ya tiene):
     * disponible, o usada en qué pedido y cuándo.
     */
    public function delComensal(User $comensal): Collection
    {
        $insignias = $comensal->badges()->whereNotNull('reward_product_id')->with('rewardProduct:id,name,price,available')->get();
        $usos = $this->usos($comensal, $insignias->pluck('id'));

        return $insignias->map(function (Badge $insignia) use ($usos) {
            $uso = $usos->get($insignia->id);
            return [
                'badge_id' => $insignia->id,
                'insignia' => $insignia->name,
                'product_id' => $insignia->reward_product_id,
                'producto' => $insignia->rewardProduct?->name,
                'estado' => $uso ? 'usada' : 'disponible',
                'pedido_id' => $uso?->order_id,
                'usada_en' => $uso?->created_at,
            ];
        })->values();
    }

    /** Insignias del comensal cuya cortesía todavía no se usó. */
    public function disponibles(User $comensal): Collection
    {
        $insignias = $comensal->badges()->whereNotNull('reward_product_id')->get();
        $usadas = $this->usos($comensal, $insignias->pluck('id'))->keys();

        return $insignias->reject(fn (Badge $b) => $usadas->contains($b->id))->values();
    }

    /**
     * Para cada plato marcado como cortesía, la insignia que lo cubre. Rechaza el
     * pedido (422) si el comensal no tiene esa cortesía disponible o si se pide más
     * de una unidad. Se llama dentro de la transacción del pedido, con el comensal
     * bloqueado, para que dos pedidos a la vez no usen la misma cortesía.
     *
     * @return array<int,int>  índice del plato en $items => badge_id
     */
    public function asignar(User $comensal, array $items): array
    {
        $libres = $this->disponibles($comensal);
        $asignadas = [];

        foreach ($items as $i => $item) {
            if (!filter_var($item['cortesia'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            if ((int) $item['quantity'] !== 1) {
                throw ValidationException::withMessages(['items' => 'La cortesía es una sola unidad']);
            }
            $insignia = $libres->first(fn (Badge $b) => (int) $b->reward_product_id === (int) $item['product_id']
                && !in_array($b->id, $asignadas, true));
            if (!$insignia) {
                throw ValidationException::withMessages([
                    'items' => "{$comensal->name} no tiene una cortesía disponible de este producto (ya la usó o aún no tiene la insignia)",
                ]);
            }
            $asignadas[$i] = $insignia->id;
        }

        return $asignadas;
    }

    /** Plato de cortesía de cada insignia, en pedidos no cancelados del comensal. */
    private function usos(User $comensal, Collection $insignias): Collection
    {
        if ($insignias->isEmpty()) {
            return collect();
        }

        return OrderItem::whereIn('courtesy_badge_id', $insignias)
            ->whereHas('order', fn ($q) => $q->where('user_id', $comensal->id)->where('status', '!=', 'cancelado'))
            ->orderBy('id')
            ->get()
            ->keyBy('courtesy_badge_id');
    }
}
