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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
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

    public function removeUserRole(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'school_id' => 'required|exists:schools,id',
            'role_name' => 'required|string|max:100',
        ]);

        $caller = auth()->user();
        $schoolId = (int) $request->school_id;

        if (!$caller || (!$caller->is_super_admin && (
            currentSchoolId() !== $schoolId ||
            !UserRole::where('user_id', $caller->id)
                ->where('roleable_type', 'school')
                ->where('roleable_id', $schoolId)
                ->whereHas('role', fn($q) => $q->where('slug', 'director'))
                ->exists()
        ))) {
            \Illuminate\Support\Facades\Log::warning('StaffController.removeUserRole: forbidden', [
                'caller_id' => $caller?->id,
                'target_user_id' => $request->user_id,
                'school_id' => $schoolId,
            ]);
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ((int) $request->user_id === $caller->id) {
            return response()->json(['message' => 'Vous ne pouvez pas retirer votre propre rôle.'], 422);
        }

        $user = User::findOrFail($request->user_id);
        $school = School::findOrFail($schoolId);

        DB::beginTransaction();

        try {
            $role = Role::where('slug', strtolower($request->role_name))->first();
            if (!$role) {
                return response()->json([
                    'message' => 'Le rôle spécifié n\'existe pas'
                ], 422);
            }

            $deleted = UserRole::where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('roleable_id', $school->id)
                ->where('roleable_type', 'school')
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'message' => 'Le rôle n\'a pas pu être supprimé'
                ], 404);
            }

            $hasRoles = UserRole::where('user_id', $user->id)->exists();

            if (!$hasRoles) {
                $user->delete();
                $message = 'L\'utilisateur a été supprimé car il n\'avait plus aucun rôle';
            } else {
                $message = 'Le rôle de l\'utilisateur pour cette école a été supprimé';
            }

            DB::commit();

            return response()->json([
                'message' => $message
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression du rôle',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
