<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('siret', 30)->nullable()->after('logo');
            $table->string('vat_mode', 20)->nullable()->after('siret');
            $table->string('vat_number', 30)->nullable()->after('vat_mode');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['siret', 'vat_mode', 'vat_number']);
        });
    }
};
