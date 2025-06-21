<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tarifs')->update([
            'prix' => DB::raw('ROUND(prix)')
        ]);

        Schema::table('tarifs', function (Blueprint $table) {
            $table->integer('prix')->change();
        });

        DB::table('lignes_paiement')->update([
            'montant' => DB::raw('ROUND(montant)')
        ]);

        Schema::table('lignes_paiement', function (Blueprint $table) {
            $table->integer('montant')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tarifs', function (Blueprint $table) {
            $table->decimal('prix', 10, 2)->change();
        });

        Schema::table('lignes_paiement', function (Blueprint $table) {
            $table->decimal('montant', 10, 2)->change();
        });
    }
};
