<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_classrooms', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('family_id')
                ->constrained('school_years')->restrictOnDelete();
        });

        // Backfill depuis classrooms.school_year_id (déjà NOT NULL depuis
        // 2026_05_25_100200_lock_school_year_constraints).
        DB::statement('
            UPDATE student_classrooms sc
            INNER JOIN classrooms c ON c.id = sc.classroom_id
            SET sc.school_year_id = c.school_year_id
            WHERE sc.school_year_id IS NULL
        ');

        $orphans = DB::table('student_classrooms')->whereNull('school_year_id')->count();
        if ($orphans > 0) {
            throw new \RuntimeException(
                "Backfill student_classrooms.school_year_id incomplet : {$orphans} ligne(s) sans annee. " .
                "Verifier la coherence avec classrooms avant de relancer."
            );
        }

        Schema::table('student_classrooms', function (Blueprint $table) {
            $table->unsignedBigInteger('school_year_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_classrooms', 'school_year_id')) {
            Schema::table('student_classrooms', function (Blueprint $table) {
                $table->unsignedBigInteger('school_year_id')->nullable()->change();
            });
            Schema::table('student_classrooms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('school_year_id');
            });
        }
    }
};
