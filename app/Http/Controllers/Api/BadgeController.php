<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Cortesias;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /** Todas las insignias, con lo que la casa invita al obtenerlas (si invita algo). */
    public function index()
    {
        $badges = Badge::with('rewardProduct:id,name')->orderBy('xp_required')->get();
        return response()->json($badges);
    }

    public function userBadges(Request $request)
    {
        $user = $request->user();
        $badges = $user->badges()->withPivot('obtained_at')->get();
        return response()->json($badges);
    }

    /**
     * Cortesías de un comensal (disponible o usada, y en qué pedido). El comensal ve
     * las suyas; el personal consulta las del comensal del pedido (?user_id=).
     */
    public function cortesias(Request $request, Cortesias $cortesias)
    {
        $usuario = $request->user();
        if ($usuario->hasRole('cliente')) {
            return response()->json($cortesias->delComensal($usuario));
        }

        $request->validate(['user_id' => 'required|exists:users,id']);
        return response()->json($cortesias->delComensal(User::findOrFail($request->user_id)));
    }

    public function assignBadge(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
        ]);

        $existing = UserBadge::where('user_id', $request->user_id)
            ->where('badge_id', $request->badge_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'El usuario ya tiene esta insignia'], 400);
        }

        $userBadge = UserBadge::create([
            'user_id' => $request->user_id,
            'badge_id' => $request->badge_id,
            'obtained_at' => now(),
        ]);

        return response()->json($userBadge, 201);
    }
}
