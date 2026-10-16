<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\SchoolRoleProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            app(SchoolRoleProvisioner::class)->syncPermissions();
            $ids = Permission::whereIn('key', ['school.settings.update', 'school_years.manage', 'roles.manage'])->pluck('id');
            // Ajout ciblé : conserve les autres personnalisations des administrateurs.
            foreach (Role::where('slug', 'admin')->get() as $role) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        });
    }

    public function down(): void
    {
        // Ne retire pas des droits qui ont pu être attribués explicitement depuis.
    }
};
