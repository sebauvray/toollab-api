<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('director_handovers', function (Blueprint $table) {
            $table->boolean('remove_teacher_role')->default(false)->after('outgoing_role');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('director_handovers', 'remove_teacher_role')) {
            Schema::table('director_handovers', function (Blueprint $table) {
                $table->dropColumn('remove_teacher_role');
            });
        }
    }
};
