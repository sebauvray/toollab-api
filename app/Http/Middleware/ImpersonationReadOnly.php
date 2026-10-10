<?php

namespace App\Http\Middleware;

use App\Models\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une session d'impersonation est en lecture seule : toute requête d'écriture
 * est refusée (sauf la sortie de session) et comptabilisée dans l'audit.
 */
class ImpersonationReadOnly
{
    private const ALLOWED_WRITES = ['api/impersonate/stop', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user('sanctum')?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->name !== Impersonation::TOKEN_NAME) {
            return $next($request);
        }

        if ($request->isMethodSafe() || $request->is(...self::ALLOWED_WRITES)) {
            return $next($request);
        }

        Impersonation::where('token_id', $token->id)->increment('blocked_writes');

        return response()->json([
            'message' => 'Mode « connecté en tant que » : lecture seule, aucune modification possible.',
            'impersonation_read_only' => true,
        ], 403);
    }
}
