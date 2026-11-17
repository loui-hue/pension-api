<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserChallenge;
use App\Models\Streak;
use App\Models\User;
use App\Models\UserBadge;

class LoyaltyService
{
    /**
     * Registra una acumulación de puntos de experiencia para el usuario
     * y revisa si con ello desbloquea nuevas insignias.
     */
    public function awardXp(User $user, int $points, ?string $reason = null): LoyaltyPoint
    {
        $loyalty = LoyaltyPoint::create([
            'user_id' => $user->id,
            'points' => $points,
            'total_xp' => $points,
            'reason' => $reason,
        ]);

        $this->checkAndAwardBadges($user);

        return $loyalty;
    }

    /**
     * Registra la visita del día y actualiza la racha de consumo del usuario.
     * Es idempotente: si ya se registró una visita hoy, no vuelve a incrementar.
     */
    public function registerVisit(User $user): Streak
    {
        $streak = Streak::firstOrCreate(
            ['user_id' => $user->id],
            ['current_streak' => 0, 'longest_streak' => 0]
        );

        $today = now()->toDateString();

        if ($streak->last_visit && $streak->last_visit->toDateString() === $today) {
            return $streak;
        }

        if ($streak->last_visit && $streak->last_visit->diffInDays(now()) === 1) {
            $streak->current_streak += 1;
        } else {
            $streak->current_streak = 1;
        }

        if ($streak->current_streak > $streak->longest_streak) {
            $streak->longest_streak = $streak->current_streak;
        }

        $streak->last_visit = $today;
        $streak->save();

        $this->checkAndAwardBadges($user);

        return $streak;
    }

    /**
     * Avance automático de los retos en los que participa el comensal, al pagar un pedido:
     * - visitas: una por día con pago (dos pagos el mismo día cuentan una sola visita);
     * - compras: una por cada pedido pagado;
     * - gastronomico: una por cada producto del pedido que el comensal nunca había pagado.
     * Los retos "especial" no avanzan solos (los registra el administrador).
     * Solo cuentan los retos activos y dentro de sus fechas. Al llegar a la meta el reto
     * se completa y otorga su XP, igual que el registro manual.
     */
    public function advanceChallenges(User $user, Order $order): void
    {
        $hoy = now()->toDateString();
        $participaciones = UserChallenge::with('challenge')
            ->where('user_id', $user->id)
            ->where('status', '!=', 'completado')
            ->get();

        $productosNuevos = null;   // se calcula una sola vez y solo si hace falta

        foreach ($participaciones as $participacion) {
            $reto = $participacion->challenge;
            if (!$reto || !$reto->active
                || ($reto->start_date && $reto->start_date->toDateString() > $hoy)
                || ($reto->end_date && $reto->end_date->toDateString() < $hoy)) {
                continue;
            }

            $suma = match ($reto->type) {
                'visitas' => $participacion->last_counted_on?->toDateString() === $hoy ? 0 : 1,
                'compras' => 1,
                'gastronomico' => $productosNuevos ??= $this->productosNuevos($user, $order),
                default => 0,
            };
            if ($suma === 0) {
                continue;
            }

            $meta = max(1, (int) $reto->goal);
            $participacion->current_count += $suma;
            $participacion->last_counted_on = $hoy;
            $participacion->progress = min(100, intdiv($participacion->current_count * 100, $meta));
            $participacion->status = 'en_progreso';
            $participacion->save();

            if ($participacion->current_count >= $meta) {
                $this->completeChallenge($participacion);
            }
        }
    }

    /**
     * Completa el reto y otorga su XP una sola vez (lo usan el avance automático
     * y el registro manual del administrador).
     */
    public function completeChallenge(UserChallenge $participacion): void
    {
        if ($participacion->status === 'completado') {
            return;
        }

        $participacion->status = 'completado';
        $participacion->progress = 100;
        $participacion->completed_at = now();
        $participacion->save();

        $reto = Challenge::findOrFail($participacion->challenge_id);
        if ($reto->xp_reward > 0) {
            $this->awardXp($participacion->user, $reto->xp_reward, "Reto completado: {$reto->name}");
        }
    }

    /** Cantidad de productos distintos del pedido que el comensal nunca había pagado antes. */
    private function productosNuevos(User $user, Order $order): int
    {
        $delPedido = $order->items()->distinct()->pluck('product_id');

        $yaProbados = OrderItem::whereHas('order', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('status', 'pagado')
            ->where('id', '!=', $order->id))
            ->distinct()
            ->pluck('product_id');

        return $delPedido->diff($yaProbados)->count();
    }

    /**
     * Suma total de XP acumulado históricamente por el usuario.
     */
    public function totalXp(User $user): int
    {
        return (int) LoyaltyPoint::where('user_id', $user->id)->sum('points');
    }

    /**
     * Otorga automáticamente las insignias cuyo hito de XP ya fue alcanzado
     * y que el usuario todavía no posee.
     */
    public function checkAndAwardBadges(User $user): void
    {
        $totalXp = $this->totalXp($user);
        $alreadyOwned = $user->badges()->pluck('badges.id')->toArray();

        $newlyEarned = Badge::whereNotIn('id', $alreadyOwned ?: [0])
            ->where('xp_required', '<=', $totalXp)
            ->get();

        foreach ($newlyEarned as $badge) {
            UserBadge::firstOrCreate(
                ['user_id' => $user->id, 'badge_id' => $badge->id],
                ['obtained_at' => now()]
            );
        }
    }
}
