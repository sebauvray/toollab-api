<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->index()->after('access');
        });

        // Amorçage : la création de token la plus récente correspond à la dernière connexion
        DB::table('users')->update([
            'last_login_at' => DB::table('personal_access_tokens')
                ->selectRaw('MAX(created_at)')
                ->whereColumn('personal_access_tokens.tokenable_id', 'users.id')
                ->where('personal_access_tokens.tokenable_type', 'App\\Models\\User'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
