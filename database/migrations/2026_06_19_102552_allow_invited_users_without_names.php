<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY first_name VARCHAR(255) NULL');
        DB::statement('ALTER TABLE users MODIFY last_name VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET first_name = '' WHERE first_name IS NULL");
        DB::statement("UPDATE users SET last_name = '' WHERE last_name IS NULL");
        DB::statement('ALTER TABLE users MODIFY first_name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE users MODIFY last_name VARCHAR(255) NOT NULL');
    }
};
