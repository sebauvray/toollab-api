<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reduction_familiales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cursus_id')->constrained('cursus')->onDelete('cascade');
            $table->integer('nombre_eleves_min');
            $table->decimal('pourcentage_reduction', 5, 2);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reduction_familiales');
    }
};
