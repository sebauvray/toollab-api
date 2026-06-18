<?php

namespace App\Support;

class StaffRolePermissions
{
    public static function canManage(array $callerRoleSlugs, string $targetRoleSlug): bool
    {
        if (in_array('director', $callerRoleSlugs, true)) {
            return in_array($targetRoleSlug, ['admin', 'registar', 'teacher'], true);
        }

        return in_array('admin', $callerRoleSlugs, true)
            && in_array($targetRoleSlug, ['registar', 'teacher'], true);
    }
}
