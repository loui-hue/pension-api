<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\LoyaltyPoint;
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
