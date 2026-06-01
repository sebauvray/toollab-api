<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('class_schedules', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('classroom_id')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('class_schedules', 'teacher_id')) {
            Schema::table('class_schedules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('teacher_id');
            });
        }
    }
};
