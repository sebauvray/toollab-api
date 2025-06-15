<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cursus', function (Blueprint $table) {
            $table->integer('levels_count')->default(1)->after('progression');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cursus', function (Blueprint $table) {
            $table->dropColumn('levels_count');
        });
    }
};
