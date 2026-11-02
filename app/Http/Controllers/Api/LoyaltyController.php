<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyaltyService)
    {
    }

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

        $user = User::findOrFail($request->user_id);
        $loyalty = $this->loyaltyService->awardXp($user, $request->points, $request->reason);

        return response()->json($loyalty, 201);
    }

    public function streak(Request $request)
    {
        $streak = $this->loyaltyService->registerVisit($request->user());
        return response()->json($streak);
    }
}