<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Pas de contrainte : le journal doit survivre à la suppression des acteurs et sujets
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->unsignedBigInteger('impersonation_id')->nullable();
            $table->string('action', 64)->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
