<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invitation_tokens', function (Blueprint $table) {
            // null = activation d'un nouveau compte ; renseigné = acceptation
            // de l'invitation d'une école précise par un utilisateur déjà existant.
            $table->foreignId('school_id')->nullable()->after('token')
                ->constrained('schools')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invitation_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
