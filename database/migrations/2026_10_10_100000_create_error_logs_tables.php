<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une ligne par erreur distincte (empreinte classe + fichier + ligne)
        Schema::create('error_groups', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('category', 16)->index();
            $table->string('exception_class');
            $table->text('message')->nullable();
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('context', 500)->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->unsignedBigInteger('last_user_id')->nullable();
            $table->unsignedBigInteger('last_school_id')->nullable();
            $table->mediumText('last_trace')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
        });

        // Une ligne par occurrence, conservée 7 jours (graphe horaire)
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('error_group_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
        Schema::dropIfExists('error_groups');
    }
};
