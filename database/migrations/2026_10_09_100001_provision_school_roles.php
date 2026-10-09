<?php

use App\Services\SchoolRoleProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(SchoolRoleProvisioner::class);
        $provisioner->syncPermissions();

        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            $provisioner->provision((int) $schoolId);
        }
    }

    // Raccroche les rattachements sur les modèles globaux puis supprime les copies.
    public function down(): void
    {
        $copies = DB::table('roles')->whereNotNull('school_id')->get(['id', 'slug']);

        foreach ($copies as $copy) {
            $templateId = DB::table('roles')->whereNull('school_id')->where('slug', $copy->slug)->value('id');
            if ($templateId) {
                DB::table('user_roles')->where('role_id', $copy->id)->update(['role_id' => $templateId]);
            }
        }

        DB::table('roles')->whereNotNull('school_id')->delete();
    }
};
