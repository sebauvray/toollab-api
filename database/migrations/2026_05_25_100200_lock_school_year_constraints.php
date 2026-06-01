<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['classrooms', 'tarifs', 'reduction_familiales', 'reduction_multi_cursuses', 'paiements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('school_year_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['classrooms', 'tarifs', 'reduction_familiales', 'reduction_multi_cursuses', 'paiements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('school_year_id')->nullable()->change();
            });
        }
    }
};
