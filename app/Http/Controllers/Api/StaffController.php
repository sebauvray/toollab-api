<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffRequest;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\StaffInvitation;
use App\Support\StaffRolePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    private function canManageRole(User $caller, int $schoolId, string $roleSlug): bool
    {
        if ($caller->is_super_admin) {
            return true;
        }

        $callerRoles = UserRole::query()
            ->where('user_id', $caller->id)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->whereHas('role', fn($query) => $query->whereIn('slug', ['director', 'admin']))
            ->with('role:id,slug')
            ->get()
            ->pluck('role.slug');

        return StaffRolePermissions::canManage($callerRoles->all(), $roleSlug);
    }

    public function createStaffUser(StaffRequest $request)
    {
        $school = School::findOrFail($request->school_id);
        $role = Role::where('slug', $request->role)->first();

        if (!$role) {
            return response()->json([
                'message' => 'Le rôle spécifié n\'existe pas'
            ], 422);
        }

        $existingUser = User::where('email', $request->email)->first();

        if ($existingUser) {
            $existingRole = $school->userRoles()
                ->where('user_id', $existingUser->id)
                ->first();

            $existingRoleWithSameType = $school->userRoles()
                ->where('user_id', $existingUser->id)
                ->where('role_id', $role->id)
                ->first();

            if ($existingRoleWithSameType) {
                $message = 'L\'utilisateur possède déjà ce rôle dans cette école.';
            } else {
                $school->userRoles()->create([
                    'user_id' => $existingUser->id,
                    'role_id' => $role->id,
                ]);
                $message = 'Nouveau rôle ajouté à l\'utilisateur existant.';
            }

            $user = $existingUser;
        } else {
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => bcrypt(Str::random(32)),
                'access' => true,
            ]);

            $school->userRoles()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);

            $token = Str::random(64);

            InvitationToken::create([
                'email' => $user->email,
                'token' => $token,
                'expires_at' => now()->addDays(7),
            ]);

            $user->notify(new StaffInvitation($school->name, $role->name, $token));

            $message = 'Utilisateur créé avec succès. Un email d\'invitation a été envoyé.';
        }

        return response()->json([
            'message' => $message,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $role->name,
            ]
        ], 201);
    }

    public function addUserRole(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'school_id' => 'required|integer|exists:schools,id',
            'role' => 'required|in:admin,registar,teacher',
        ]);

        $caller = auth()->user();
        $schoolId = (int) $validated['school_id'];
        $roleSlug = $validated['role'];

        if (!$caller
            || currentSchoolId() !== $schoolId
            || !$this->canManageRole($caller, $schoolId, $roleSlug)
        ) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $alreadyBelongsToSchool = UserRole::query()
            ->where('user_id', $validated['user_id'])
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->exists();

        if (!$alreadyBelongsToSchool) {
            return response()->json([
                'message' => 'Cet utilisateur n’appartient pas à cette école.'
            ], 422);
        }

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $school = School::findOrFail($schoolId);
        $userRole = $school->userRoles()->firstOrCreate([
            'user_id' => $validated['user_id'],
            'role_id' => $role->id,
        ]);

        return response()->json([
            'message' => $userRole->wasRecentlyCreated
                ? 'Le rôle a été ajouté avec succès.'
                : 'L’utilisateur possède déjà ce rôle dans cette école.',
            'role' => [
                'name' => $role->name,
                'slug' => $role->slug,
            ],
        ], $userRole->wasRecentlyCreated ? 201 : 200);
    }

    public function removeUserRole(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'school_id' => 'required|exists:schools,id',
            'role_name' => 'required|in:admin,registar,teacher',
        ]);

        $caller = auth()->user();
        $schoolId = (int) $validated['school_id'];
        $roleSlug = strtolower($validated['role_name']);

        if (!$caller
            || currentSchoolId() !== $schoolId
            || !$this->canManageRole($caller, $schoolId, $roleSlug)
        ) {
            \Illuminate\Support\Facades\Log::warning('StaffController.removeUserRole: forbidden', [
                'caller_id' => $caller?->id,
                'target_user_id' => $request->user_id,
                'school_id' => $schoolId,
            ]);
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ((int) $validated['user_id'] === $caller->id) {
            return response()->json(['message' => 'Vous ne pouvez pas retirer votre propre rôle.'], 422);
        }

        $user = User::findOrFail($validated['user_id']);
        $school = School::findOrFail($schoolId);
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        DB::beginTransaction();

        try {
            $deleted = UserRole::where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('roleable_id', $school->id)
                ->where('roleable_type', 'school')
                ->delete();

            if (!$deleted) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Le rôle n\'a pas pu être supprimé'
                ], 404);
            }

            // On ne supprime jamais le compte : il peut conserver des rôles ailleurs,
            // un historique, et rester utilisable. Pour sortir un membre de l'école,
            // utiliser removeUserFromSchool.
            DB::commit();

            return response()->json([
                'message' => 'Le rôle de l\'utilisateur pour cette école a été supprimé'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression du rôle',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function removeUserFromSchool(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'school_id' => 'required|exists:schools,id',
        ]);

        $caller = auth()->user();
        $schoolId = (int) $validated['school_id'];
        $targetUserId = (int) $validated['user_id'];

        if (!$caller || currentSchoolId() !== $schoolId) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ($targetUserId === $caller->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous retirer vous-même.'], 422);
        }

        $targetRoleSlugs = UserRole::query()
            ->where('user_id', $targetUserId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->with('role:id,slug')
            ->get()
            ->pluck('role.slug')
            ->filter()
            ->unique()
            ->all();

        if (empty($targetRoleSlugs)) {
            return response()->json(['message' => 'Cet utilisateur n\'appartient pas à cette école.'], 404);
        }

        if (!$caller->is_super_admin) {
            $callerRoles = UserRole::query()
                ->where('user_id', $caller->id)
                ->where('roleable_type', 'school')
                ->where('roleable_id', $schoolId)
                ->whereHas('role', fn($query) => $query->whereIn('slug', ['director', 'admin']))
                ->with('role:id,slug')
                ->get()
                ->pluck('role.slug')
                ->all();

            foreach ($targetRoleSlugs as $slug) {
                if (!StaffRolePermissions::canManage($callerRoles, $slug)) {
                    \Illuminate\Support\Facades\Log::warning('StaffController.removeUserFromSchool: forbidden', [
                        'caller_id' => $caller->id,
                        'target_user_id' => $targetUserId,
                        'school_id' => $schoolId,
                        'target_role' => $slug,
                    ]);
                    return response()->json(['message' => 'Accès refusé'], 403);
                }
            }
        }

        UserRole::query()
            ->where('user_id', $targetUserId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->delete();

        return response()->json([
            'message' => 'L\'utilisateur a été retiré de l\'établissement.'
        ], 200);
    }
}
