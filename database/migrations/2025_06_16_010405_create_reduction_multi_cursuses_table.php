<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reduction_multi_cursuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cursus_beneficiaire_id')->constrained('cursus')->onDelete('cascade');
            $table->foreignId('cursus_requis_id')->constrained('cursus')->onDelete('cascade');
            $table->decimal('pourcentage_reduction', 5, 2);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['cursus_beneficiaire_id', 'cursus_requis_id'], 'unique_cursus_reduction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reduction_multi_cursuses');
    }
};
