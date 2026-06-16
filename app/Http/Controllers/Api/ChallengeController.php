<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\UserChallenge;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function index()
    {
        $challenges = Challenge::where('active', true)->get();
        return response()->json($challenges);
    }

    public function userChallenges(Request $request)
    {
        $user = $request->user();
        $challenges = $user->challenges()
            ->withPivot('status', 'progress', 'completed_at')
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

        $userChallenge = UserChallenge::findOrFail($id);
        $userChallenge->progress = $request->progress;

        if ($request->progress >= 100) {
            $userChallenge->status = 'completado';
            $userChallenge->completed_at = now();
        }

        $userChallenge->save();
        return response()->json($userChallenge);
    }
}