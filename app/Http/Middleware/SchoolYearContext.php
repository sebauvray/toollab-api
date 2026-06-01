<?php

namespace App\Http\Middleware;

use App\Models\SchoolYear;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout l'année scolaire courante.
 * - Si header X-School-Year-Id fourni : valide qu'il appartient à l'école courante.
 * - Sinon : prend l'année active de l'école, ou la dernière si aucune n'est active.
 * - Bloque toute mutation (POST/PUT/PATCH/DELETE) si l'année courante est archivée.
 * À monter APRÈS SchoolContext.
 */
class SchoolYearContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $raw = $request->header('X-School-Year-Id');

        if ($raw !== null && $raw !== '') {
            if (!ctype_digit((string) $raw) || (int) $raw <= 0) {
                Log::warning('SchoolYearContext: invalid X-School-Year-Id', [
                    'school_id' => $schoolId,
                    'raw' => $raw,
                    'path' => $request->path(),
                ]);
                return response()->json(['message' => 'Requête invalide'], 400);
            }

            $year = SchoolYear::query()
                ->withoutGlobalScopes()
                ->where('id', (int) $raw)
                ->where('school_id', $schoolId)
                ->first();

            if (!$year) {
                Log::warning('SchoolYearContext: year not in current school', [
                    'school_id' => $schoolId,
                    'year_id' => (int) $raw,
                    'path' => $request->path(),
                ]);
                return response()->json(['message' => 'Accès refusé'], 403);
            }
        } else {
            $year = SchoolYear::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->first();

            if (!$year) {
                // Pas d'année active : on bascule sur la plus récente pour permettre
                // la consultation de l'historique. Les mutations sont rejetées plus bas
                // par le check read-only (toute année non-active est read-only).
                $year = SchoolYear::query()
                    ->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->orderByDesc('closed_at')
                    ->orderByDesc('id')
                    ->first();

                if (!$year) {
                    Log::warning('SchoolYearContext: no school year at all', [
                        'school_id' => $schoolId,
                        'path' => $request->path(),
                    ]);
                    return response()->json(['message' => 'Aucune année scolaire'], 409);
                }
            }
        }

        $readOnly = !$year->is_active || $year->closed_at !== null;

        $request->attributes->set('current_school_year_id', $year->id);

        if ($readOnly && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            Log::warning('SchoolYearContext: write attempt on archived year', [
                'school_id' => $schoolId,
                'year_id' => $year->id,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
            return response()->json([
                'message' => 'Année scolaire en lecture seule',
                'read_only' => true,
            ], 409);
        }

        return $next($request);
    }
}
