<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('main_teacher_id')->nullable()->after('level_id')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement("
            UPDATE classrooms c
            SET c.main_teacher_id = (
                SELECT cs.teacher_id FROM class_schedules cs
                WHERE cs.classroom_id = c.id AND cs.teacher_id IS NOT NULL
                ORDER BY cs.id ASC LIMIT 1
            )
        ");
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_teacher_id');
        });
    }
};
