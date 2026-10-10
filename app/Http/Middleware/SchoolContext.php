<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SchoolContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $raw = $request->header('X-School-Id');
        if ($raw === null || $raw === '' || !ctype_digit((string) $raw) || (int) $raw <= 0) {
            Log::warning('SchoolContext: invalid or missing X-School-Id', [
                'user_id' => $user->id,
                'raw' => $raw,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Aucune école sélectionnée.'], 400);
        }

        $schoolId = (int) $raw;

        $school = School::find($schoolId);

        if (!$school
            || (!$user->is_super_admin && !$this->userHasAccess($user->id, $schoolId))
        ) {
            Log::warning('SchoolContext: access denied', [
                'user_id' => $user->id,
                'requested_school_id' => $schoolId,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Vous n’avez pas accès à cette école.'], 403);
        }

        // École suspendue par le super-admin : plus aucun accès pour son équipe
        if ($school->isSuspended() && !$user->is_super_admin) {
            return response()->json([
                'message' => 'Cet établissement est suspendu. Contactez le support Toollab.',
                'school_suspended' => true,
            ], 403);
        }

        $request->attributes->set('current_school_id', $schoolId);

        return $next($request);
    }

    private function userHasAccess(int $userId, int $schoolId): bool
    {
        // Seul le staff accède à une école (adhésion acceptée). Les membres de
        // famille ou de classe n'ont pas d'espace dans l'outil.
        return UserRole::query()
            ->where('user_id', $userId)
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $schoolId)
            ->whereNotNull('accepted_at')
            ->exists();
    }
}
