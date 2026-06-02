<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->boolean('outcomes_open')->default(false)->after('is_active');
        });

        Schema::create('student_year_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->enum('outcome', ['passage', 'redoublement', 'exclusion', 'fin_cursus']);
            $table->text('commentaire')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'school_year_id', 'classroom_id'], 'syo_student_year_class_unique');
            $table->index(['school_year_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_year_outcomes');

        if (Schema::hasColumn('school_years', 'outcomes_open')) {
            Schema::table('school_years', function (Blueprint $table) {
                $table->dropColumn('outcomes_open');
            });
        }
    }
};
