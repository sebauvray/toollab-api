<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')->constrained('paiements')->onDelete('cascade');
            $table->enum('type_paiement', ['espece', 'carte', 'cheque', 'exoneration']);
            $table->decimal('montant', 10, 2);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('paiement_id');
            $table->index('type_paiement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_paiement');
    }
};
