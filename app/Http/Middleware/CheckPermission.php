<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * permission:cle1,cle2 — laisse passer si l'utilisateur a au moins une des
 * permissions dans l'école courante (rôles acceptés uniquement).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $user = Auth::user();

        if ($user->is_super_admin) {
            return $next($request);
        }

        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            Log::warning('CheckPermission: missing school context', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Aucune école sélectionnée.'], 400);
        }

        if ($user->hasPermissionIn($schoolId, ...$permissions)) {
            return $next($request);
        }

        Log::warning('CheckPermission: insufficient permission', [
            'user_id' => $user->id,
            'school_id' => $schoolId,
            'required' => $permissions,
        ]);
        return response()->json(['message' => 'Vous n’avez pas les droits nécessaires pour effectuer cette action.'], 403);
    }
}
