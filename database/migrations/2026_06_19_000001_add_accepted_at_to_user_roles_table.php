<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('roleable_type');
        });

        // Les adhésions existantes sont considérées comme déjà acceptées :
        // on ne veut pas masquer le nom des utilisateurs actifs aujourd'hui.
        DB::table('user_roles')->update(['accepted_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
    }
};
