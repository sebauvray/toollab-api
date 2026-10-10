<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Désactivation / réactivation d'un compte utilisateur (super-admin). */
class UserAdminController extends Controller
{
    public function disable(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:500']);

        if ($user->is_super_admin) {
            return response()->json(['message' => "Un compte super-admin ne peut pas être désactivé."], 403);
        }
        if (! $user->access) {
            return response()->json(['message' => 'Ce compte est déjà désactivé.'], 409);
        }

        $user->forceFill([
            'access' => false,
            'disabled_at' => now(),
            'disabled_reason' => $validated['reason'],
        ])->save();

        // Coupe immédiatement toutes les sessions en cours, y compris « en tant que »
        $revoked = $user->tokens()->delete();

        Audit::log('user.disabled', null, $user, ['reason' => $validated['reason'], 'sessions_revoked' => $revoked]);

        return response()->json($this->present($user));
    }

    public function enable(User $user): JsonResponse
    {
        if ($user->access) {
            return response()->json(['message' => "Ce compte n'est pas désactivé."], 409);
        }

        $user->forceFill(['access' => true, 'disabled_at' => null, 'disabled_reason' => null])->save();
        Audit::log('user.enabled', null, $user);

        return response()->json($this->present($user));
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'access' => (bool) $user->access,
            'disabled_at' => $user->disabled_at,
            'disabled_reason' => $user->disabled_reason,
        ];
    }
}
