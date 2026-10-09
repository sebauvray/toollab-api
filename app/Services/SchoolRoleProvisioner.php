<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Donne à une école ses propres rôles staff, copiés des modèles globaux, puis
 * raccroche dessus les rattachements de l'école qui pointent encore sur un
 * modèle. Idempotent : n'écrase jamais les permissions d'un rôle déjà copié,
 * l'école a pu les modifier.
 */
class SchoolRoleProvisioner
{
    private const SCHOOL_ROLEABLE_TYPES = ['school', 'App\\Models\\School'];

    public function syncPermissions(): void
    {
        foreach (PermissionCatalog::PERMISSIONS as $key => $meta) {
            Permission::updateOrCreate(['key' => $key], $meta);
        }
    }

    public function provision(int $schoolId): void
    {
        $this->syncPermissions();
        $permissionIds = Permission::pluck('id', 'key');

        DB::transaction(function () use ($schoolId, $permissionIds) {
            foreach (PermissionCatalog::STAFF_SLUGS as $slug) {
                $template = Role::global()->where('slug', $slug)->firstOrFail();

                $role = Role::firstOrCreate(
                    ['school_id' => $schoolId, 'slug' => $slug],
                    [
                        'name' => $template->name,
                        'description' => $template->description,
                        'is_locked' => in_array($slug, PermissionCatalog::LOCKED_SLUGS, true),
                    ]
                );

                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync(
                        $permissionIds->only(PermissionCatalog::defaultsFor($slug))->values()
                    );
                }

                $this->moveAssignments($schoolId, $template->id, $role->id);
            }
        });
    }

    /**
     * SQL brut : les global scopes de UserRole (corbeille, année close) masqueraient
     * des lignes qu'il faut aussi raccrocher.
     */
    private function moveAssignments(int $schoolId, int $fromRoleId, int $toRoleId): void
    {
        $assignments = DB::table('user_roles')
            ->whereIn('roleable_type', self::SCHOOL_ROLEABLE_TYPES)
            ->where('roleable_id', $schoolId);

        // Une même personne déjà rattachée aux deux : on garde la copie, sinon
        // l'update violerait la contrainte d'unicité user/rôle/contexte.
        $alreadyOnCopy = (clone $assignments)->where('role_id', $toRoleId)->pluck('user_id');
        (clone $assignments)->where('role_id', $fromRoleId)->whereIn('user_id', $alreadyOnCopy)->delete();

        (clone $assignments)->where('role_id', $fromRoleId)->update(['role_id' => $toRoleId]);
    }
}
