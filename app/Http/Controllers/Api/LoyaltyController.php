<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\Streak;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $points = LoyaltyPoint::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($points);
    }

    public function addPoints(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'points' => 'required|integer|min:1',
            'reason' => 'nullable|string',
        ]);

        $loyalty = LoyaltyPoint::create([
            'user_id' => $request->user_id,
            'points' => $request->points,
            'total_xp' => $request->points,
            'reason' => $request->reason,
        ]);

        return response()->json($loyalty, 201);
    }

    public function streak(Request $request)
    {
        $user = $request->user();
        $streak = Streak::firstOrCreate(
            ['user_id' => $user->id],
            ['current_streak' => 0, 'longest_streak' => 0]
        );

        $today = now()->toDateString();

        if ($streak->last_visit && $streak->last_visit->toDateString() === $today) {
            return response()->json($streak);
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

        return response()->json($streak);
    }
}