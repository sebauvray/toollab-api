<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\UserRole;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = Auth::user();

        $userRoles = UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'school')
            ->with('role')
            ->get();

        $userRoleNames = $userRoles->pluck('role.slug')->toArray();

        $hasRole = false;
        foreach ($roles as $role) {
            if (in_array($role, $userRoleNames)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            return response()->json(['message' => 'Permissions insuffisantes'], 403);
        }

        return $next($request);
    }
}
