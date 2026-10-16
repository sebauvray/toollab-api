<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Anti-escalade : qui peut attribuer ou retirer quel rôle dans une école.
 * - un rôle verrouillé (Directeur) ne s'attribue jamais ;
 * - school.manage (directeur) permet tout autre rôle ;
 * - sinon il faut une permission de gestion de l'équipe (inviter, rôles,
 *   retrait), et les droits du rôle cible doivent être strictement inclus
 *   dans ceux de l'appelant : un admin ne nomme pas d'admin. Chaque route
 *   vérifie en plus sa propre permission (staff.invite, staff.roles…).
 * L'espace professeur est ignoré dans la comparaison : il ne donne accès
 * qu'à ses propres classes, rien sur les autres.
 */
class StaffRolePermissions
{
    private const SELF_SCOPED = ['teaching.access', 'teaching.attendance', 'teaching.outcomes'];

    /** Permissions qui donnent la main sur les rôles d'autrui. */
    private const STAFF_MANAGING = ['staff.invite', 'staff.roles', 'staff.remove', 'roles.manage'];

    /** Modifier un profil ne permet ni de gérer ses rôles ni son email de connexion. */
    public static function canUpdateMember(User $caller, User $target, int $schoolId): bool
    {
        if ($caller->id === $target->id || $caller->is_super_admin) {
            return true;
        }
        if ($target->is_super_admin || !$caller->hasPermissionIn($schoolId, 'staff.update')) {
            return false;
        }
        $roles = $target->roles()->whereIn('roleable_type', ['school', \App\Models\School::class])
            ->where('roleable_id', $schoolId)->with('role.permissions')->get()->pluck('role')->filter();
        if ($roles->isEmpty() || $roles->contains(fn ($role) => $role->is_locked || in_array($role->slug, PermissionCatalog::LOCKED_SLUGS, true))) {
            return false;
        }
        $callerKeys = $caller->permissionKeysIn($schoolId);
        if (in_array(PermissionCatalog::SCHOOL_MANAGE, $callerKeys, true)) {
            return true;
        }
        $callerKeys = array_values(array_diff($callerKeys, self::SELF_SCOPED));
        $targetKeys = array_values(array_diff($roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique()->all(), self::SELF_SCOPED));

        return array_diff($targetKeys, $callerKeys) === [] && count($targetKeys) < count($callerKeys);
    }

    public static function canManage(User $caller, int $schoolId, string $roleSlug): bool
    {
        if ($caller->is_super_admin) {
            return true;
        }

        $role = Role::forSchool($schoolId)->where('slug', $roleSlug)->first()
            ?? Role::global()->where('slug', $roleSlug)->first();
        if (!$role) {
            return false;
        }

        return self::canAssign(
            $caller->permissionKeysIn($schoolId),
            $role->permissions()->pluck('key')->all(),
            $role->is_locked || in_array($role->slug, PermissionCatalog::LOCKED_SLUGS, true)
        );
    }

    public static function canAssign(array $callerKeys, array $targetKeys, bool $targetLocked): bool
    {
        if ($targetLocked) {
            return false;
        }

        if (in_array(PermissionCatalog::SCHOOL_MANAGE, $callerKeys, true)) {
            return true;
        }

        if (!array_intersect(self::STAFF_MANAGING, $callerKeys)) {
            return false;
        }

        $caller = array_values(array_diff($callerKeys, self::SELF_SCOPED));
        $target = array_values(array_diff($targetKeys, self::SELF_SCOPED));

        return array_diff($target, $caller) === [] && count($target) < count($caller);
    }
}
