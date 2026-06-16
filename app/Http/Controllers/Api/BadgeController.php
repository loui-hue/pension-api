<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\UserBadge;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    public function index()
    {
        $badges = Badge::all();
        return response()->json($badges);
    }

    public function userBadges(Request $request)
    {
        $user = $request->user();
        $badges = $user->badges()->withPivot('obtained_at')->get();
        return response()->json($badges);
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