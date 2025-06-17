<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique('family_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
