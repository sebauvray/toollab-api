<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression réversible d'une famille (corbeille).
 *
 * Trois tables portent le deleted_at car supprimer une famille signifie couper
 * ses rattachements, pas détruire des personnes :
 *   - families           : la famille elle-même
 *   - user_roles         : les liens « X est élève/responsable dans la famille 12 »
 *                          et « X est élève dans la classe 7 »
 *   - student_classrooms : les inscriptions en classe
 *
 * On ne touche NI à users (comptes partageables entre écoles), NI à paiements
 * (pièces comptables), NI à comments (historique du dossier).
 */
return new class extends Migration {
    private const TABLES = ['families', 'user_roles', 'student_classrooms'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
