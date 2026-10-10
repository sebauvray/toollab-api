<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Impersonation;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Point d'entrée unique du journal d'audit.
 *
 *   Audit::log('staff.role_added', $schoolId, $user, ['role' => 'teacher']);
 *
 * L'acteur est l'utilisateur authentifié (null pour une action publique par jeton) ;
 * une action faite « en tant que » est rattachée à sa session d'impersonation.
 * Un échec d'écriture est loggé mais ne fait jamais échouer l'action métier.
 */
class Audit
{
    public const ACTIONS = [
        'school.created' => 'École créée',
        'school.updated' => 'École modifiée',
        'school.deleted' => 'École supprimée',
        'school.suspended' => 'École suspendue',
        'school.reactivated' => 'École réactivée',
        'school.director_contacted' => 'Directeur contacté',
        'staff.invited' => 'Membre invité',
        'staff.role_added' => 'Rôle ajouté',
        'staff.role_removed' => 'Rôle retiré',
        'staff.removed_from_school' => "Retiré de l'école",
        'invitation.accepted' => 'Invitation acceptée',
        'invitation.declined' => 'Invitation refusée',
        'role.created' => 'Rôle personnalisé créé',
        'role.updated' => 'Rôle personnalisé modifié',
        'role.deleted' => 'Rôle personnalisé supprimé',
        'handover.initiated' => 'Passation de direction lancée',
        'handover.accepted' => 'Passation de direction acceptée',
        'handover.declined' => 'Passation de direction refusée',
        'handover.cancelled' => 'Passation de direction annulée',
        'family.deleted' => 'Famille mise à la corbeille',
        'family.restored' => 'Famille restaurée',
        'family.purged' => 'Famille purgée définitivement',
        'user.deleted' => 'Utilisateur supprimé',
        'user.disabled' => 'Compte désactivé',
        'user.enabled' => 'Compte réactivé',
        'school_year.closed' => 'Année scolaire clôturée',
        'impersonation.started' => 'Connexion « en tant que »',
        'feature.toggled' => 'Fonctionnalité activée/désactivée',
    ];

    public static function log(string $action, ?int $schoolId = null, ?Model $subject = null, array $meta = [], ?User $actor = null): void
    {
        try {
            $request = request();
            $actor ??= $request?->user();
            $token = $actor?->currentAccessToken();
            $impersonationId = $token instanceof PersonalAccessToken && $token->name === Impersonation::TOKEN_NAME
                ? Impersonation::where('token_id', $token->id)->value('id')
                : null;

            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_label' => $actor ? self::userLabel($actor) : null,
                'impersonation_id' => $impersonationId,
                'action' => $action,
                'school_id' => $schoolId,
                'subject_type' => $subject ? self::subjectType($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? self::subjectLabel($subject) : null,
                'meta' => $meta ?: null,
                'ip_address' => $request?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit: écriture impossible', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    public static function userLabel(User $user): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? "{$name} ({$user->email})" : $user->email;
    }

    private static function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => 'user',
            $subject instanceof School => 'school',
            $subject instanceof Family => 'family',
            default => strtolower(class_basename($subject)),
        };
    }

    private static function subjectLabel(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof User => self::userLabel($subject),
            $subject instanceof School => $subject->name,
            $subject instanceof Family => "Famille #{$subject->getKey()}",
            default => $subject->name ?? $subject->label ?? null,
        };
    }
}
