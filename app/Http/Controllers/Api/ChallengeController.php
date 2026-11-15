<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\UserChallenge;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function __construct(private LoyaltyService $loyaltyService)
    {
    }

    public function index()
    {
        $challenges = Challenge::where('active', true)->get();
        return response()->json($challenges);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'xp_reward' => 'required|integer|min:0',
            'goal' => 'sometimes|integer|min:1',
            'type' => 'required|in:visitas,compras,gastronomico,especial',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'active' => 'boolean',
        ]);

        $challenge = Challenge::create($validated);

        return response()->json($challenge, 201);
    }

    public function userChallenges(Request $request)
    {
        $user = $request->user();
        $challenges = $user->challenges()
            ->withPivot('status', 'progress', 'current_count', 'completed_at')
            ->get();
        return response()->json($challenges);
    }

    public function join(Request $request)
    {
        $request->validate([
            'challenge_id' => 'required|exists:challenges,id',
        ]);

        $user = $request->user();

        $existing = UserChallenge::where('user_id', $user->id)
            ->where('challenge_id', $request->challenge_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ya estás participando en este reto'], 400);
        }

        $userChallenge = UserChallenge::create([
            'user_id' => $user->id,
            'challenge_id' => $request->challenge_id,
            'status' => 'en_progreso',
            'progress' => 0,
        ]);

        return response()->json($userChallenge, 201);
    }

    public function updateProgress(Request $request, $id)
    {
        $request->validate([
            'progress' => 'required|integer|min:0|max:100',
        ]);

        // Registro manual del administrador: para los retos "especial", que no
        // dependen de los pagos (los demás avanzan solos al cobrar un pedido).
        $userChallenge = UserChallenge::findOrFail($id);

        if ($request->progress >= 100) {
            $this->loyaltyService->completeChallenge($userChallenge);
            return response()->json($userChallenge->fresh());
        }

        if ($userChallenge->status !== 'completado') {
            $userChallenge->progress = $request->progress;
            $userChallenge->save();
        }
        return response()->json($userChallenge);
    }
}