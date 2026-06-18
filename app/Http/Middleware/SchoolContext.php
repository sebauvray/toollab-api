<?php

namespace App\Http\Middleware;

use App\Models\Classroom;
use App\Models\Family;
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

        if (!School::whereKey($schoolId)->exists()
            || (!$user->is_super_admin && !$this->userHasAccess($user->id, $schoolId))
        ) {
            Log::warning('SchoolContext: access denied', [
                'user_id' => $user->id,
                'requested_school_id' => $schoolId,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Vous n’avez pas accès à cette école.'], 403);
        }

        $request->attributes->set('current_school_id', $schoolId);

        return $next($request);
    }

    private function userHasAccess(int $userId, int $schoolId): bool
    {
        $direct = UserRole::query()
            ->where('user_id', $userId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->exists();
        if ($direct) {
            return true;
        }

        // withoutGlobalScopes : currentSchoolId() n'est pas encore set ici,
        // le scope fail-closed retournerait 0 rows et bloquerait l'accès.
        $familyIds = Family::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');
        if ($familyIds->isNotEmpty()) {
            $viaFamily = UserRole::query()
                ->where('user_id', $userId)
                ->where('roleable_type', 'family')
                ->whereIn('roleable_id', $familyIds)
                ->exists();
            if ($viaFamily) {
                return true;
            }
        }

        $classroomIds = Classroom::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');
        if ($classroomIds->isNotEmpty()) {
            $viaClassroom = UserRole::query()
                ->where('user_id', $userId)
                ->where('roleable_type', 'classroom')
                ->whereIn('roleable_id', $classroomIds)
                ->exists();
            if ($viaClassroom) {
                return true;
            }
        }

        return false;
    }
}
