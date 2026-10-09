<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Anti-escalade : qui peut attribuer ou retirer quel rôle dans une école.
 * - un rôle verrouillé (Directeur) ne s'attribue jamais ;
 * - roles.manage permet tout autre rôle (son détenteur peut de toute façon
 *   créer n'importe quel rôle) ;
 * - sinon il faut staff.manage, et les droits du rôle cible doivent être
 *   strictement inclus dans ceux de l'appelant : un admin ne nomme pas d'admin.
 * teaching.access est ignoré dans la comparaison : il ne donne accès qu'à ses
 * propres classes, rien sur les autres.
 */
class StaffRolePermissions
{
    private const SELF_SCOPED = ['teaching.access'];

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

        if (in_array('roles.manage', $callerKeys, true)) {
            return true;
        }

        if (!in_array('staff.manage', $callerKeys, true)) {
            return false;
        }

        $caller = array_values(array_diff($callerKeys, self::SELF_SCOPED));
        $target = array_values(array_diff($targetKeys, self::SELF_SCOPED));

        return array_diff($target, $caller) === [] && count($target) < count($caller);
    }
}
