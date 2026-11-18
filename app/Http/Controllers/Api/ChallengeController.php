<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\UserChallenge;
use App\Reglas\ReglasFidelizacion;
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

    /** Pantalla "Gestionar retos": todos los retos (también inactivos) con cuántos se unieron. */
    public function gestion()
    {
        return response()->json(
            Challenge::withCount('users as participantes')->orderByDesc('active')->orderBy('id')->get()
        );
    }

    /** Comensales unidos a un reto, con su avance hacia la meta (p. ej. 1 de 3). */
    public function participantes($id)
    {
        $reto = Challenge::findOrFail($id);
        $participaciones = UserChallenge::with('user:id,name,email,phone')
            ->where('challenge_id', $reto->id)
            ->orderByRaw("status = 'completado'")
            ->orderBy('id')
            ->get()
            ->map(fn (UserChallenge $p) => [
                'id' => $p->id,
                'user' => $p->user,
                'status' => $p->status,
                'current_count' => (int) $p->current_count,
                'goal' => max(1, (int) $reto->goal),
                'progress' => (int) $p->progress,
                'completed_at' => $p->completed_at,
            ]);

        return response()->json(['challenge' => $reto, 'participantes' => $participaciones]);
    }

    /**
     * Registro manual del administrador, solo para los retos "especial" (los demás
     * avanzan solos al cobrar un pedido). Cada registro suma una unidad verificada en
     * persona (p. ej. un amigo nuevo que vino a la pensión); al llegar a la meta el
     * reto se completa y otorga su XP una sola vez.
     * {id} es la participación del comensal (user_challenges.id).
     */
    public function updateProgress($id)
    {
        $participacion = UserChallenge::with('challenge')->findOrFail($id);
        $reto = $participacion->challenge;

        if ($reto->type !== 'especial') {
            return response()->json([
                'message' => "El reto «{$reto->name}» avanza solo al cobrar los pedidos: no se registra a mano",
            ], 422);
        }
        if ($participacion->status === 'completado') {
            return response()->json(['message' => "Este comensal ya completó el reto «{$reto->name}»"], 422);
        }

        $meta = max(1, (int) $reto->goal);
        $participacion->current_count += 1;
        $participacion->progress = ReglasFidelizacion::progreso($participacion->current_count, $meta);
        $participacion->status = 'en_progreso';
        $participacion->save();

        if (ReglasFidelizacion::retoCompletado($participacion->current_count, $meta)) {
            $this->loyaltyService->completeChallenge($participacion);
        }

        return response()->json($participacion->fresh());
    }
}
