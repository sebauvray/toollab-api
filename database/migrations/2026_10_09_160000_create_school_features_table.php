<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seules les surcharges sont stockées : sans ligne, la valeur par défaut du catalogue s'applique
        Schema::create('school_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->boolean('enabled');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_features');
    }
};
