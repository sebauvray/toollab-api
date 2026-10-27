<?php

use App\Services\SchoolRoleProvisioner;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions par bloc du menu, une par action (consulter, créer, modifier,
 * supprimer…) : convertit les droits de chaque rôle existant (copies d'école,
 * rôles personnalisés) vers les nouvelles clés, puis aligne le catalogue.
 * Les permissions de gestion existantes sont conservées.
 */
return new class extends Migration
{
    private const MAP = [
        'school.settings.update' => ['school.settings.update'],
        'school_years.manage' => ['school_years.manage'],
        'roles.manage' => ['roles.manage'],
        'staff.view' => ['staff.view'],
        'staff.manage' => ['staff.view', 'staff.invite', 'staff.update', 'staff.roles', 'staff.remove'],
        // Voir une famille donnait aussi accès à ses paiements
        'families.view' => ['families.view', 'payments.view'],
        'families.edit' => ['families.view', 'families.create', 'families.update', 'students.create', 'students.update', 'students.delete', 'comments.create'],
        'families.import_export' => ['families.view', 'families.import', 'families.export'],
        'families.delete' => ['families.view', 'families.delete'],
        'enrollments.manage' => ['families.view', 'enrollments.create', 'enrollments.delete'],
        'payments.edit' => ['payments.view', 'payments.create', 'payments.update', 'payments.delete'],
        'cursus.manage' => ['cursus.view', 'cursus.create', 'cursus.update', 'cursus.delete'],
        'classrooms.manage' => ['classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete'],
        'classrooms.supervise' => ['classrooms.view', 'classrooms.export', 'classrooms.remove_student'],
        'teaching.access' => ['teaching.access', 'teaching.attendance', 'teaching.outcomes'],
        'tarification.manage' => ['tarification.view', 'tarification.update', 'reductions.create', 'reductions.update', 'reductions.delete'],
        'statistics.view' => ['statistics.view', 'statistics.export'],
    ];

    public function up(): void
    {
        $converted = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->get(['role_permission.role_id', 'permissions.key'])
            ->groupBy('role_id')
            ->map(fn ($rows) => PermissionCatalog::normalize(
                collect($rows)->flatMap(fn ($row) => self::MAP[$row->key] ?? [$row->key])->all()
            ));

        $provisioner = app(SchoolRoleProvisioner::class);

        DB::transaction(function () use ($converted, $provisioner) {
            // Crée les nouvelles clés sans supprimer les anciennes : la conversion
            // doit être écrite avant que le nettoyage n'efface role_permission.
            foreach (PermissionCatalog::definitions() as $key => $meta) {
                DB::table('permissions')->updateOrInsert(['key' => $key], $meta + ['updated_at' => now(), 'created_at' => now()]);
            }
            $ids = DB::table('permissions')->pluck('id', 'key');

            foreach ($converted as $roleId => $keys) {
                DB::table('role_permission')->where('role_id', $roleId)->delete();
                DB::table('role_permission')->insert(
                    collect($keys)->filter(fn ($key) => isset($ids[$key]))
                        ->map(fn ($key) => ['role_id' => $roleId, 'permission_id' => $ids[$key]])
                        ->values()
                        ->all()
                );
            }

            $provisioner->syncPermissions();
        });
    }

    // Conversion avec perte (plusieurs clés fusionnées) : pas de retour arrière.
    public function down(): void
    {
    }
};
