<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:custom_roles` — refuse la route si la fonctionnalité est désactivée pour l'école courante. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Features::enabled($feature)) {
            return response()->json([
                'message' => "Cette fonctionnalité n'est pas activée pour votre établissement.",
                'feature_disabled' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
