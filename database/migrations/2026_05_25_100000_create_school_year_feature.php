<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('label');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'label']);
            $table->index(['school_id', 'is_active']);
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('school_id')
                ->constrained('school_years')->restrictOnDelete();
        });

        Schema::table('tarifs', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('cursus_id')
                ->constrained('school_years')->restrictOnDelete();
        });

        Schema::table('reduction_familiales', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('cursus_id')
                ->constrained('school_years')->restrictOnDelete();
        });

        Schema::table('reduction_multi_cursuses', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('id')
                ->constrained('school_years')->restrictOnDelete();
            $table->unique(['cursus_beneficiaire_id', 'cursus_requis_id', 'school_year_id'], 'rmc_beneficiaire_requis_year_unique');
            $table->index('cursus_beneficiaire_id', 'rmc_cursus_beneficiaire_idx');
        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE reduction_multi_cursuses DROP INDEX `unique_cursus_reduction`');

        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('family_id')
                ->constrained('school_years')->restrictOnDelete();
            $table->unique(['family_id', 'school_year_id'], 'paiements_family_year_unique');
        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE paiements DROP INDEX `paiements_family_id_unique`');

        Schema::table('student_classrooms', function (Blueprint $table) {
            $table->json('tarif_snapshot')->nullable()->after('enrollment_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_classrooms', 'tarif_snapshot')) {
            Schema::table('student_classrooms', fn (Blueprint $t) => $t->dropColumn('tarif_snapshot'));
        }

        if (Schema::hasColumn('paiements', 'school_year_id')) {
            Schema::table('paiements', fn (Blueprint $t) => $t->unique('family_id'));
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE paiements DROP INDEX `paiements_family_year_unique`');
            Schema::table('paiements', fn (Blueprint $t) => $t->dropConstrainedForeignId('school_year_id'));
        }

        if (Schema::hasColumn('reduction_multi_cursuses', 'school_year_id')) {
            Schema::table('reduction_multi_cursuses', fn (Blueprint $t) => $t->unique(['cursus_beneficiaire_id', 'cursus_requis_id'], 'unique_cursus_reduction'));
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE reduction_multi_cursuses DROP INDEX `rmc_beneficiaire_requis_year_unique`');
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE reduction_multi_cursuses DROP INDEX `rmc_cursus_beneficiaire_idx`');
            Schema::table('reduction_multi_cursuses', fn (Blueprint $t) => $t->dropConstrainedForeignId('school_year_id'));
        }

        foreach (['reduction_familiales', 'tarifs', 'classrooms'] as $table) {
            if (Schema::hasColumn($table, 'school_year_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('school_year_id'));
            }
        }

        Schema::dropIfExists('school_years');
    }
};
