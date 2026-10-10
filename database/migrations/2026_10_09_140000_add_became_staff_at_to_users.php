<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trace durable du statut staff : les rôles école sont supprimés en forceDelete,
        // il ne resterait sinon rien pour distinguer un ancien staff d'un compte famille.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('became_staff_at')->nullable()->after('last_login_at');
        });

        DB::table('users')->update([
            'became_staff_at' => DB::table('user_roles')
                ->selectRaw('MIN(accepted_at)')
                ->whereColumn('user_roles.user_id', 'users.id')
                ->whereNotNull('accepted_at')
                ->whereIn('roleable_type', ['school', 'App\\Models\\School']),
        ]);

        // Comptes sans aucun rôle : historiquement, d'anciens staff retirés de leur école
        DB::table('users')
            ->whereNull('became_staff_at')
            ->whereNotExists(fn ($q) => $q->from('user_roles')->whereColumn('user_roles.user_id', 'users.id'))
            ->update(['became_staff_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('became_staff_at');
        });
    }
};
