<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('user_roles')
            ->whereIn('roleable_type', ['school', 'App\\Models\\School'])
            ->whereNotNull('deleted_at')
            ->delete();
    }

    // Purge irréversible : les rôles école ne relèvent pas de la corbeille famille.
    public function down(): void
    {
    }
};
