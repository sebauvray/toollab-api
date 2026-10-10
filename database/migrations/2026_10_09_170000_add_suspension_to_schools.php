<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `access` reste la source de vérité ; ces colonnes expliquent une suspension
        Schema::table('schools', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('access');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
