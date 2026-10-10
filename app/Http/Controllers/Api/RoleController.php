<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\UserRole;
use App\Support\PermissionCatalog;
use App\Support\StaffRolePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rôles de l'école courante et leurs permissions. Le rôle Directeur est
 * verrouillé ; les rôles par défaut se modifient mais ne se suppriment pas
 * (des comportements métier reposent sur leur slug, ex. teacher).
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $schoolId = (int) currentSchoolId();
        $caller = auth()->user();

        $usersCount = UserRole::query()
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $schoolId)
            ->selectRaw('role_id, count(distinct user_id) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $order = array_flip(PermissionCatalog::STAFF_SLUGS);
        $roles = Role::forSchool($schoolId)
            ->with('permissions:id,key')
            ->get()
            ->sortBy(fn (Role $role) => [$order[$role->slug] ?? 99, $role->name])
            ->values()
            ->map(fn (Role $role) => $this->present($role, (int) ($usersCount[$role->id] ?? 0), $caller, $schoolId));

        return response()->json([
            'roles' => $roles,
            'permissions' => collect(PermissionCatalog::PERMISSIONS)
                ->map(fn ($meta, $key) => ['key' => $key] + $meta)
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = (int) currentSchoolId();
        $validated = $this->validateRole($request, $schoolId);

        $role = DB::transaction(function () use ($validated, $schoolId) {
            $role = Role::create([
                'school_id' => $schoolId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'slug' => $this->uniqueSlug($validated['name'], $schoolId),
                'is_locked' => false,
            ]);
            $this->syncPermissions($role, $validated['permissions']);

            return $role;
        });
        Audit::log('role.created', $schoolId, $role, ['permissions' => array_values($validated['permissions'])]);

        return response()->json($this->present($role->fresh('permissions'), 0, auth()->user(), $schoolId), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $schoolId = (int) currentSchoolId();
        if ($deny = $this->denyIfNotEditable($role, $schoolId)) return $deny;

        $validated = $this->validateRole($request, $schoolId, $role);
        $before = $role->permissions()->pluck('key')->all();

        DB::transaction(function () use ($role, $validated) {
            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);
            $this->syncPermissions($role, $validated['permissions']);
        });

        $after = $role->permissions()->pluck('key')->all();
        Audit::log('role.updated', $schoolId, $role, array_filter([
            'granted' => array_values(array_diff($after, $before)),
            'revoked' => array_values(array_diff($before, $after)),
            'renamed' => $role->wasChanged('name') ? $role->name : null,
        ]));

        return response()->json($this->present($role->fresh('permissions'), $this->usersCount($role, $schoolId), auth()->user(), $schoolId));
    }

    public function destroy(Role $role): JsonResponse
    {
        $schoolId = (int) currentSchoolId();
        if ($deny = $this->denyIfNotEditable($role, $schoolId)) return $deny;

        if (in_array($role->slug, PermissionCatalog::STAFF_SLUGS, true)) {
            return response()->json(['message' => 'Un rôle par défaut ne peut pas être supprimé.'], 422);
        }

        $count = $this->usersCount($role, $schoolId);
        if ($count > 0) {
            return response()->json([
                'message' => "Ce rôle est encore attribué à {$count} personne(s) : retirez-le-leur avant de le supprimer.",
            ], 409);
        }

        Audit::log('role.deleted', $schoolId, $role);
        $role->delete();

        return response()->json(null, 204);
    }

    private function validateRole(Request $request, int $schoolId, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('roles', 'name')->where('school_id', $schoolId)->ignore($role?->id),
            ],
            'description' => 'nullable|string|max:255',
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(PermissionCatalog::PERMISSIONS))],
        ], [
            'name.unique' => 'Un rôle porte déjà ce nom dans votre établissement.',
        ]);
    }

    // Rôle d'une autre école : 404, on ne confirme pas son existence.
    private function denyIfNotEditable(Role $role, int $schoolId): ?JsonResponse
    {
        if ($role->school_id !== $schoolId) {
            return response()->json(['message' => 'Rôle introuvable.'], 404);
        }
        if ($role->is_locked) {
            return response()->json(['message' => 'Ce rôle est verrouillé et ne peut pas être modifié.'], 403);
        }

        return null;
    }

    private function syncPermissions(Role $role, array $keys): void
    {
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
    }

    private function uniqueSlug(string $name, int $schoolId): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;
        while (in_array($slug, PermissionCatalog::STAFF_SLUGS, true)
            || in_array($slug, ['student', 'responsible', 'super-admin'], true)
            || Role::forSchool($schoolId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function usersCount(Role $role, int $schoolId): int
    {
        return UserRole::query()
            ->where('role_id', $role->id)
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $schoolId)
            ->distinct('user_id')
            ->count('user_id');
    }

    private function present(Role $role, int $usersCount, $caller, int $schoolId): array
    {
        $keys = $role->permissions->pluck('key')->values()->all();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_locked' => $role->is_locked,
            'is_default' => in_array($role->slug, PermissionCatalog::STAFF_SLUGS, true),
            'permissions' => $keys,
            'users_count' => $usersCount,
            'assignable' => $caller->is_super_admin
                ? !$role->is_locked
                : StaffRolePermissions::canAssign($caller->permissionKeysIn($schoolId), $keys, $role->is_locked),
        ];
    }
}
