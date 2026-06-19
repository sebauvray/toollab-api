<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffRequest;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\SchoolInvitationNotification;
use App\Notifications\StaffInvitation;
use App\Notifications\StaffRoleChangedNotification;
use App\Support\StaffRolePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    private const STAFF_ROLE_ORDER = ['admin', 'registar', 'teacher'];

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

    private function requestedRoleSlugs(Request $request): array
    {
        $roles = $request->input('roles');
        if (!is_array($roles) || empty($roles)) {
            $roles = [$request->input('role')];
        }

        return $this->sortRoleSlugs(array_values(array_filter(array_unique($roles))));
    }

    private function sortRoleSlugs(array $slugs): array
    {
        $order = array_flip(self::STAFF_ROLE_ORDER);

        usort($slugs, function ($a, $b) use ($order) {
            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });

        return $slugs;
    }

    private function roleNamesFromSlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $roles = Role::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        return collect($this->sortRoleSlugs($slugs))
            ->map(fn ($slug) => $roles[$slug]->name ?? $slug)
            ->values()
            ->all();
    }

    private function currentSchoolRoleNames(User $user, int $schoolId): array
    {
        $slugs = UserRole::query()
            ->where('user_id', $user->id)
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $schoolId)
            ->with('role:id,slug')
            ->get()
            ->pluck('role.slug')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->roleNamesFromSlugs($slugs);
    }

    public function createStaffUser(StaffRequest $request)
    {
        $school = School::findOrFail($request->school_id);
        $roleSlugs = $this->requestedRoleSlugs($request);
        $roles = Role::query()
            ->whereIn('slug', $roleSlugs)
            ->get()
            ->keyBy('slug');
        $primaryRole = $roles[$request->role] ?? $roles->first();

        if (!$primaryRole || $roles->count() !== count($roleSlugs)) {
            return response()->json([
                'message' => 'Le rôle spécifié n\'existe pas'
            ], 422);
        }

        $existingUser = User::where('email', $request->email)->first();

        // Une adhésion à l'école n'est "acceptée" (et le nom visible par l'école) que
        // lorsque l'utilisateur a activé son compte ou accepté l'invitation via connexion.
        $alreadyAccepted = $existingUser
            ? $school->userRoles()
                ->where('user_id', $existingUser->id)
                ->whereNotNull('accepted_at')
                ->exists()
            : false;

        [$user, $message, $createdRoleSlugs] = DB::transaction(function () use ($request, $school, $roles, $roleSlugs, $existingUser, $alreadyAccepted) {
            $user = $existingUser;

            if (!$user) {
                $user = User::create([
                    'first_name' => $request->filled('first_name') ? $request->first_name : null,
                    'last_name' => $request->filled('last_name') ? $request->last_name : null,
                    'email' => $request->email,
                    'password' => bcrypt(Str::random(32)),
                    'access' => true,
                ]);
            }

            // Les adhésions sont créées "en attente" (accepted_at = null) : tant que
            // l'utilisateur n'a pas accepté, l'école ne voit pas son identité.
            $createdRoleSlugs = [];
            foreach ($roleSlugs as $roleSlug) {
                $userRole = $school->userRoles()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'role_id' => $roles[$roleSlug]->id,
                    ],
                    // Si l'utilisateur a déjà accepté cette école, les nouveaux rôles
                    // naissent acceptés (son nom est déjà visible) ; sinon ils restent
                    // en attente jusqu'à l'acceptation de l'invitation.
                    $alreadyAccepted ? ['accepted_at' => now()] : []
                );

                if ($userRole->wasRecentlyCreated) {
                    $createdRoleSlugs[] = $roleSlug;
                }
            }

            // Nouvel utilisateur : invitation classique (activation du compte + mot de passe).
            if (!$existingUser) {
                $token = Str::random(64);

                InvitationToken::create([
                    'email' => $user->email,
                    'token' => $token,
                    'expires_at' => now()->addDays(7),
                ]);

                $user->notify(new StaffInvitation(
                    $school->name,
                    $this->roleNamesFromSlugs($roleSlugs),
                    $token
                ));

                return [$user, 'Utilisateur créé avec succès. Un email d\'invitation a été envoyé.', $createdRoleSlugs];
            }

            // Utilisateur existant qui n'a pas encore accepté pour cette école :
            // on (re)génère un token lié à l'école et on envoie une invitation à accepter.
            if (!$alreadyAccepted) {
                $token = Str::random(64);

                InvitationToken::updateOrCreate(
                    ['email' => $user->email, 'school_id' => $school->id],
                    ['token' => $token, 'expires_at' => now()->addDays(7)]
                );

                $user->notify(new SchoolInvitationNotification(
                    $school->name,
                    $this->roleNamesFromSlugs($roleSlugs),
                    $token
                ));

                return [$user, 'Invitation envoyée. Le nom de l\'utilisateur sera visible après son acceptation.', $createdRoleSlugs];
            }

            if (!empty($createdRoleSlugs)) {
                $user->notify(new StaffRoleChangedNotification(
                    $school->name,
                    'added',
                    $this->roleNamesFromSlugs($createdRoleSlugs),
                    $this->currentSchoolRoleNames($user, $school->id)
                ));

                return [$user, 'Nouveau rôle ajouté à l\'utilisateur existant.', $createdRoleSlugs];
            }

            return [$user, 'L\'utilisateur possède déjà ce rôle dans cette école.', $createdRoleSlugs];
        });

        // Tant que l'invitation n'est pas acceptée, l'école ne doit pas voir le nom.
        $pending = !$alreadyAccepted;

        return response()->json([
            'message' => $message,
            'user' => [
                'id' => $user->id,
                'first_name' => $pending ? null : $user->first_name,
                'last_name' => $pending ? null : $user->last_name,
                'email' => $user->email,
                'pending' => $pending,
                'role' => $primaryRole->name,
                'roles' => $this->currentSchoolRoleNames($user, $school->id),
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

        if ($userRole->wasRecentlyCreated) {
            $user = User::findOrFail($validated['user_id']);
            $user->notify(new StaffRoleChangedNotification(
                $school->name,
                'added',
                [$role->name],
                $this->currentSchoolRoleNames($user, $school->id)
            ));
        }

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

            $remainingRoleNames = $this->currentSchoolRoleNames($user, $school->id);
            $user->notify(new StaffRoleChangedNotification(
                $school->name,
                'removed',
                [$role->name],
                $remainingRoleNames
            ));

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

        $user = User::findOrFail($targetUserId);
        $removedRoleNames = $this->roleNamesFromSlugs($targetRoleSlugs);

        UserRole::query()
            ->where('user_id', $targetUserId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->delete();

        $school = School::findOrFail($schoolId);
        $user->notify(new StaffRoleChangedNotification(
            $school->name,
            'removed_from_school',
            $removedRoleNames,
            []
        ));

        return response()->json([
            'message' => 'L\'utilisateur a été retiré de l\'établissement.'
        ], 200);
    }
}
