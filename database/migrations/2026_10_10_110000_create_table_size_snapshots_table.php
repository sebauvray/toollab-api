<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une photo par jour et par table (prise à la première consultation de l'admin)
        Schema::create('table_size_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('table_name', 64);
            $table->unsignedBigInteger('rows');
            $table->unsignedBigInteger('data_bytes');
            $table->unsignedBigInteger('index_bytes');

            $table->unique(['snapshot_date', 'table_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_size_snapshots');
    }
};
