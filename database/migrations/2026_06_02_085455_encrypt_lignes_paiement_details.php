<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_paiement', function (Blueprint $table) {
            $table->mediumText('details')->nullable()->change();
        });

        DB::table('lignes_paiement')
            ->whereNotNull('details')
            ->orderBy('id')
            ->chunkById(100, function ($lignes) {
                foreach ($lignes as $ligne) {
                    try {
                        Crypt::decryptString($ligne->details);
                        continue;
                    } catch (DecryptException) {
                        // Existing plaintext JSON, encrypt it below.
                    }

                    DB::table('lignes_paiement')
                        ->where('id', $ligne->id)
                        ->update(['details' => Crypt::encryptString($ligne->details)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('lignes_paiement')
            ->whereNotNull('details')
            ->orderBy('id')
            ->chunkById(100, function ($lignes) {
                foreach ($lignes as $ligne) {
                    try {
                        $details = Crypt::decryptString($ligne->details);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('lignes_paiement')
                        ->where('id', $ligne->id)
                        ->update(['details' => $details]);
                }
            });

        Schema::table('lignes_paiement', function (Blueprint $table) {
            $table->json('details')->nullable()->change();
        });
    }
};
