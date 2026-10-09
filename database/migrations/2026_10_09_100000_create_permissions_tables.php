<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group');
            $table->string('label');
            $table->timestamps();
        });

        // school_id null : rôle global (modèle staff, ou rôle famille student/responsible).
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained('schools')->cascadeOnDelete();
            $table->boolean('is_locked')->default(false)->after('slug');
            $table->unique(['school_id', 'slug']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');

        Schema::table('roles', function (Blueprint $table) {
            // La clé étrangère s'appuie sur l'index unique : elle part en premier.
            $table->dropForeign(['school_id']);
            $table->dropUnique(['school_id', 'slug']);
            $table->dropColumn('school_id');
            $table->dropColumn('is_locked');
        });

        Schema::dropIfExists('permissions');
    }
};
