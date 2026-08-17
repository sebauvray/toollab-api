<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * StudentClassroom utilise TrackChangesTrait, qui renseigne updated_by sur
 * l'événement `updating` — mais la colonne n'a jamais été créée : la migration
 * 2025_06_27_171836 n'a ajouté que created_by, contrairement aux huit autres
 * tables suivies qui ont bien les deux.
 *
 * Le trou était invisible tant qu'aucun code ne modifiait une inscription via
 * le modèle (enroll ne faisait que créer et supprimer). Dès qu'on met une ligne
 * à jour — c'est le cas de restore() sur une inscription remise en service —
 * la requête part avec `SET updated_by = ?` et MySQL renvoie 1054.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('student_classrooms', 'updated_by')) {
            return;
        }

        Schema::table('student_classrooms', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('student_classrooms', 'updated_by')) {
            return;
        }

        Schema::table('student_classrooms', function (Blueprint $table) {
            $table->dropColumn('updated_by');
        });
    }
};
