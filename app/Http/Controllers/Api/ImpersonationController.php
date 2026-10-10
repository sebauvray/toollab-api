<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * « Se connecter en tant que » (super-admin, lecture seule).
 *
 * Délivre un token Sanctum court au nom de l'utilisateur cible. Le front met
 * de côté le token admin et le restaure à la sortie. La lecture seule est
 * imposée par le middleware ImpersonationReadOnly.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:255']);
        $admin = $request->user();

        if ($this->isImpersonating($request)) {
            return response()->json(['message' => 'Une session « en tant que » est déjà en cours.'], 409);
        }
        if ($user->is_super_admin) {
            return response()->json(['message' => "Impossible de se connecter en tant qu'un super-admin."], 403);
        }
        if (! $user->access) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }
        if (! $user->isStaff()) {
            return response()->json(['message' => 'Seuls les membres du staff d\'une école peuvent être consultés « en tant que ».'], 403);
        }

        $expiresAt = now()->addMinutes(Impersonation::DURATION_MINUTES);
        $newToken = $user->createToken(Impersonation::TOKEN_NAME, ['read-only'], $expiresAt);

        $impersonation = Impersonation::create([
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
            'token_id' => $newToken->accessToken->id,
            'reason' => $validated['reason'],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        Audit::log('impersonation.started', null, $user, ['reason' => $validated['reason'], 'impersonation_id' => $impersonation->id], $admin);

        Log::info('Impersonation démarrée', [
            'impersonation_id' => $impersonation->id,
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
        ]);

        return response()->json([
            'token' => $newToken->plainTextToken,
            'user' => $user,
            'impersonation' => [
                'id' => $impersonation->id,
                'expires_at' => $expiresAt,
                'admin' => ['id' => $admin->id, 'name' => trim("{$admin->first_name} {$admin->last_name}")],
            ],
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $this->isImpersonating($request)) {
            return response()->json(['message' => 'Aucune session « en tant que » en cours.'], 400);
        }

        Impersonation::where('token_id', $token->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        $token->delete();

        return response()->json(['message' => 'Session terminée.']);
    }

    /** Journal d'audit (super-admin). */
    public function index(): JsonResponse
    {
        $rows = Impersonation::with(['admin:id,first_name,last_name,email', 'target:id,first_name,last_name,email'])
            ->latest('started_at')
            ->limit(100)
            ->get()
            ->map(fn (Impersonation $i) => [
                'id' => $i->id,
                'admin' => $i->admin,
                'target' => $i->target,
                'reason' => $i->reason,
                'ip_address' => $i->ip_address,
                'blocked_writes' => $i->blocked_writes,
                'started_at' => $i->started_at,
                'ended_at' => $i->ended_at,
                'expires_at' => $i->expires_at,
                'status' => $i->ended_at ? 'ended' : ($i->expires_at->isPast() ? 'expired' : 'active'),
            ]);

        return response()->json($rows);
    }

    private function isImpersonating(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->name === Impersonation::TOKEN_NAME;
    }
}
