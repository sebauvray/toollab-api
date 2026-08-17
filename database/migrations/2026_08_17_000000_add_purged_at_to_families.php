<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deuxième étage du cycle de vie d'une famille.
 *
 *   deleted_at renseigné                    → ARCHIVÉE, restaurable
 *   deleted_at + purged_at renseignés       → SUPPRIMÉE définitivement
 *
 * « Définitivement » du point de vue de l'utilisateur seulement : la ligne
 * reste en base, elle n'est simplement plus restaurable ni listée dans
 * l'archive. Rien n'est jamais détruit — on peut toujours revenir en arrière
 * par un accès direct à la base.
 *
 * Un timestamp plutôt qu'un booléen : il se teste comme un booléen
 * (whereNull / whereNotNull) mais dit en plus QUAND, et purged_by dit QUI.
 * C'est la convention du projet (deleted_at, closed_at, accepted_at).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('families', 'purged_at')) {
            return;
        }

        Schema::table('families', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('deleted_at');
            $table->foreignId('purged_by')->nullable()->after('purged_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('families', 'purged_at')) {
            return;
        }

        Schema::table('families', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purged_by');
            $table->dropColumn('purged_at');
        });
    }
};
