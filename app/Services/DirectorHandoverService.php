<?php

namespace App\Services;

use App\Exceptions\DirectorHandoverException;
use App\Models\DirectorHandover;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\DirectorHandoverInvitation;
use App\Notifications\DirectorHandoverStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class DirectorHandoverService
{
    private const SCHOOL_ROLEABLE_TYPES = ['school', School::class];

    private array $roleIds = [];

    public function isDirectorOf(int $userId, int $schoolId): bool
    {
        return $this->schoolRolesQuery($userId, $schoolId)
            ->where('role_id', $this->roleId('director'))
            ->whereNotNull('accepted_at')
            ->exists();
    }

    public function currentFor(int $schoolId): ?DirectorHandover
    {
        return DirectorHandover::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->pending()
            ->latest('id')
            ->first();
    }

    public function initiate(School $school, User $director, string $email, string $outgoingRole, bool $removeTeacherRole = false): DirectorHandover
    {
        $email = Str::lower(trim($email));

        if (strcasecmp($email, (string) $director->email) === 0) {
            throw new DirectorHandoverException('Vous ne pouvez pas vous transmettre la direction à vous-même.');
        }

        $target = User::where('email', $email)->first();
        if ($target && $this->isDirectorOf($target->id, $school->id)) {
            throw new DirectorHandoverException('Cette personne est déjà directeur de l\'établissement.');
        }

        return DB::transaction(function () use ($school, $director, $email, $outgoingRole, $removeTeacherRole) {
            School::whereKey($school->id)->lockForUpdate()->first();

            DirectorHandover::withoutGlobalScopes()
                ->where('school_id', $school->id)
                ->pending()
                ->where('expires_at', '<=', now())
                ->update(['status' => DirectorHandover::STATUS_EXPIRED, 'updated_at' => now()]);

            $alreadyPending = DirectorHandover::withoutGlobalScopes()
                ->where('school_id', $school->id)
                ->pending()
                ->exists();

            if ($alreadyPending) {
                throw new DirectorHandoverException('Une passation de direction est déjà en cours pour cet établissement.', 409);
            }

            $token = Str::random(64);

            $handover = new DirectorHandover([
                'email' => $email,
                'outgoing_role' => $outgoingRole,
                'remove_teacher_role' => $removeTeacherRole,
            ]);
            $handover->school_id = $school->id;
            $handover->from_user_id = $director->id;
            $handover->token_hash = DirectorHandover::hashToken($token);
            $handover->status = DirectorHandover::STATUS_PENDING;
            $handover->expires_at = now()->addDays(DirectorHandover::TTL_DAYS);
            $handover->save();

            $this->sendInvitation($handover, $school, $director, $token);

            return $handover;
        });
    }

    public function resend(int $handoverId, School $school, User $director): DirectorHandover
    {
        return DB::transaction(function () use ($handoverId, $school, $director) {
            $handover = $this->lockForSchool($handoverId, $school->id);

            if ($handover->from_user_id !== $director->id) {
                throw new DirectorHandoverException('Seul le directeur à l\'origine de la passation peut renvoyer l\'invitation.', 403);
            }

            $token = Str::random(64);
            $handover->token_hash = DirectorHandover::hashToken($token);
            $handover->expires_at = now()->addDays(DirectorHandover::TTL_DAYS);
            $handover->save();

            $this->sendInvitation($handover, $school, $director, $token);

            return $handover;
        });
    }

    public function cancel(int $handoverId, int $schoolId): DirectorHandover
    {
        return DB::transaction(function () use ($handoverId, $schoolId) {
            $handover = $this->lockForSchool($handoverId, $schoolId);

            $handover->status = DirectorHandover::STATUS_CANCELLED;
            $handover->responded_at = now();
            $handover->save();

            return $handover;
        });
    }

    public function findActionable(string $token): ?DirectorHandover
    {
        $handover = DirectorHandover::withoutGlobalScopes()
            ->where('token_hash', DirectorHandover::hashToken($token))
            ->first();

        return $handover && $handover->isActionable() ? $handover : null;
    }

    public function describe(DirectorHandover $handover): array
    {
        $user = User::where('email', $handover->email)->first();
        $school = School::find($handover->school_id);

        return [
            'school_name' => $school?->name,
            'from_name' => $this->displayName($handover->fromUser),
            'email' => $handover->email,
            'has_account' => (bool) $user,
            'requires_password' => $this->requiresPassword($user),
            'requires_profile' => !$user || blank($user->first_name) || blank($user->last_name),
            'expires_at' => $handover->expires_at,
        ];
    }

    public function accept(string $token, array $input): DirectorHandover
    {
        return DB::transaction(function () use ($token, $input) {
            $handover = DirectorHandover::withoutGlobalScopes()
                ->where('token_hash', DirectorHandover::hashToken($token))
                ->lockForUpdate()
                ->first();

            if (!$handover || !$handover->isActionable()) {
                throw new DirectorHandoverException('Le lien de passation est invalide ou a expiré.', 404);
            }

            $school = School::findOrFail($handover->school_id);
            $outgoing = User::findOrFail($handover->from_user_id);

            if (!$this->isDirectorOf($outgoing->id, $school->id)) {
                throw new DirectorHandoverException('Cette passation n\'est plus valide.', 409);
            }

            $user = User::where('email', $handover->email)->lockForUpdate()->first();

            if ($user && $user->id === $outgoing->id) {
                throw new DirectorHandoverException('Cette passation n\'est plus valide.', 409);
            }

            $user = $this->resolveIncomingUser($user, $handover->email, $input);

            $this->promote($user, $school->id);
            $this->demote($outgoing, $school->id, $handover->outgoing_role, $handover->remove_teacher_role);
            $outgoing->tokens()->delete();

            InvitationToken::where('email', $user->email)
                ->where('school_id', $school->id)
                ->delete();

            $handover->status = DirectorHandover::STATUS_ACCEPTED;
            $handover->to_user_id = $user->id;
            $handover->responded_at = now();
            $handover->save();

            $outgoing->notify(new DirectorHandoverStatusNotification(
                $school->name,
                DirectorHandover::STATUS_ACCEPTED,
                $this->displayName($user),
                $this->schoolRoleNames($outgoing->id, $school->id)
            ));

            return $handover;
        });
    }

    public function decline(string $token): DirectorHandover
    {
        return DB::transaction(function () use ($token) {
            $handover = DirectorHandover::withoutGlobalScopes()
                ->where('token_hash', DirectorHandover::hashToken($token))
                ->lockForUpdate()
                ->first();

            if (!$handover || !$handover->isActionable()) {
                throw new DirectorHandoverException('Le lien de passation est invalide ou a expiré.', 404);
            }

            $handover->status = DirectorHandover::STATUS_DECLINED;
            $handover->responded_at = now();
            $handover->save();

            $school = School::find($handover->school_id);
            $handover->fromUser?->notify(new DirectorHandoverStatusNotification(
                $school?->name ?? '',
                DirectorHandover::STATUS_DECLINED,
                $handover->email,
                []
            ));

            return $handover;
        });
    }

    private function lockForSchool(int $handoverId, int $schoolId): DirectorHandover
    {
        $handover = DirectorHandover::withoutGlobalScopes()
            ->whereKey($handoverId)
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first();

        if (!$handover || $handover->status !== DirectorHandover::STATUS_PENDING) {
            throw new DirectorHandoverException('Aucune passation en cours.', 404);
        }

        return $handover;
    }

    private function requiresPassword(?User $user): bool
    {
        if (!$user) {
            return true;
        }

        return InvitationToken::where('email', $user->email)->exists()
            && !UserRole::where('user_id', $user->id)->whereNotNull('accepted_at')->exists();
    }

    private function resolveIncomingUser(?User $user, string $email, array $input): User
    {
        if (!$this->requiresPassword($user)) {
            return $user;
        }

        $needsProfile = !$user || blank($user->first_name) || blank($user->last_name);

        $rules = ['password' => ['required', 'string', 'confirmed', Password::min(8)]];
        if ($needsProfile) {
            $rules['first_name'] = 'required|string|max:255';
            $rules['last_name'] = 'required|string|max:255';
        }

        $data = Validator::make($input, $rules, [
            'password.required' => 'Le mot de passe est requis.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'first_name.required' => 'Le prénom est requis.',
            'last_name.required' => 'Le nom est requis.',
            'first_name.max' => 'Le prénom est trop long.',
            'last_name.max' => 'Le nom est trop long.',
        ])->validate();

        if (!$user) {
            return User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $email,
                'password' => $data['password'],
                'access' => true,
            ]);
        }

        if ($needsProfile) {
            $user->first_name = $data['first_name'];
            $user->last_name = $data['last_name'];
        }
        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();

        return $user;
    }

    private function promote(User $user, int $schoolId): void
    {
        $this->grantSchoolRole($user->id, $schoolId, 'director');

        $this->schoolRolesQuery($user->id, $schoolId)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now()]);

        $this->revokeSchoolRoles($user->id, $schoolId, ['admin', 'registar']);
    }

    private function demote(User $user, int $schoolId, string $outgoingRole, bool $removeTeacherRole): void
    {
        $revoked = array_diff(['director', 'admin', 'registar'], [$outgoingRole]);
        if ($removeTeacherRole) {
            $revoked[] = 'teacher';
        }

        $this->revokeSchoolRoles($user->id, $schoolId, array_values($revoked));

        if ($outgoingRole !== 'none') {
            $this->grantSchoolRole($user->id, $schoolId, $outgoingRole);
        }
    }

    private function grantSchoolRole(int $userId, int $schoolId, string $slug): void
    {
        $existing = $this->schoolRolesQuery($userId, $schoolId)
            ->withTrashed()
            ->where('role_id', $this->roleId($slug))
            ->first();

        if (!$existing) {
            UserRole::create([
                'user_id' => $userId,
                'role_id' => $this->roleId($slug),
                'roleable_type' => 'school',
                'roleable_id' => $schoolId,
                'accepted_at' => now(),
            ]);

            return;
        }

        if ($existing->trashed()) {
            $existing->restore();
        }
        if ($existing->accepted_at === null) {
            $existing->accepted_at = now();
            $existing->save();
        }
    }

    private function revokeSchoolRoles(int $userId, int $schoolId, array $slugs): void
    {
        $this->schoolRolesQuery($userId, $schoolId)
            ->withTrashed()
            ->whereIn('role_id', array_map(fn ($slug) => $this->roleId($slug), $slugs))
            ->forceDelete();
    }

    private function schoolRolesQuery(int $userId, int $schoolId)
    {
        return UserRole::query()
            ->where('user_id', $userId)
            ->whereIn('roleable_type', self::SCHOOL_ROLEABLE_TYPES)
            ->where('roleable_id', $schoolId);
    }

    private function roleId(string $slug): int
    {
        return $this->roleIds[$slug] ??= (int) Role::where('slug', $slug)->value('id')
            ?: throw new \RuntimeException("Rôle manquant : {$slug}");
    }

    private function sendInvitation(DirectorHandover $handover, School $school, User $director, string $token): void
    {
        Notification::route('mail', $handover->email)->notify(new DirectorHandoverInvitation(
            $school->name,
            $this->displayName($director),
            $token,
            $handover->expires_at
        ));
    }

    private function schoolRoleNames(int $userId, int $schoolId): array
    {
        return $this->schoolRolesQuery($userId, $schoolId)
            ->with('role:id,name')
            ->get()
            ->pluck('role.name')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return '';
        }

        return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: (string) $user->email;
    }
}
