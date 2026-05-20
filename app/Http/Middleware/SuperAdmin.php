<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if (!$user->is_super_admin) {
            Log::warning('SuperAdmin: access denied', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return $next($request);
    }
}
