<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur journalier des connexions (courbe d'activité du tableau de bord admin).
 * last_login_at ne garde que la dernière connexion de chaque compte : sans ce
 * compteur, aucun historique n'est possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_logins', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('logins')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_logins');
    }
};
