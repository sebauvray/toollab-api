<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('account_email_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('old_email');
            $table->string('new_email');
            $table->string('token_hash', 64)->unique();
            $table->string('password_fingerprint', 64);
            $table->boolean('recovery')->default(false);
            $table->timestamp('expires_at');
        });
    }
    public function down(): void { Schema::dropIfExists('account_email_changes'); }
};
