<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\UserRole;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
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
            Log::warning('CheckRole: missing school context', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $userRoleSlugs = UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->with('role')
            ->get()
            ->pluck('role.slug')
            ->all();

        foreach ($roles as $role) {
            if (in_array($role, $userRoleSlugs, true)) {
                return $next($request);
            }
        }

        Log::warning('CheckRole: insufficient role', [
            'user_id' => $user->id,
            'school_id' => $schoolId,
            'required' => $roles,
            'user_roles' => $userRoleSlugs,
        ]);
        return response()->json(['message' => 'Accès refusé'], 403);
    }
}
