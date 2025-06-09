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
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('cursus_id')->nullable()->after('school_id')->constrained('cursus');
            $table->foreignId('level_id')->nullable()->after('cursus_id')->constrained('cursus_levels');
            $table->string('gender')->after('size')->default('Mixte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropForeign(['cursus_id']);
            $table->dropForeign(['level_id']);
            $table->dropColumn(['cursus_id', 'level_id', 'gender']);
        });
    }
};
