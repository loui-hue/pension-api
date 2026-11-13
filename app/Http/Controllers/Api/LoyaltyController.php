<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\Streak;
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

    /**
     * Consulta la racha del usuario. Solo lee: la visita se registra al pagar un
     * pedido (PaymentController), no al abrir la pantalla de fidelización.
     */
    public function streak(Request $request)
    {
        $streak = Streak::firstOrNew(
            ['user_id' => $request->user()->id],
            ['current_streak' => 0, 'longest_streak' => 0]
        );

        // Si la última visita fue antes de ayer, la racha ya se cortó.
        if ($streak->last_visit && $streak->last_visit->lt(now()->subDay()->startOfDay())) {
            $streak->current_streak = 0;
        }

        return response()->json($streak);
    }
}