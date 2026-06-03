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
        Schema::table('comments', function (Blueprint $table) {
            $table->mediumText('content')->change();
        });

        DB::table('comments')
            ->orderBy('id')
            ->chunkById(100, function ($comments) {
                foreach ($comments as $comment) {
                    try {
                        Crypt::decryptString($comment->content);
                        continue;
                    } catch (DecryptException) {
                        // Existing plaintext comment, encrypt it below.
                    }

                    DB::table('comments')
                        ->where('id', $comment->id)
                        ->update(['content' => Crypt::encryptString($comment->content)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('comments')
            ->orderBy('id')
            ->chunkById(100, function ($comments) {
                foreach ($comments as $comment) {
                    try {
                        $content = Crypt::decryptString($comment->content);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('comments')
                        ->where('id', $comment->id)
                        ->update(['content' => $content]);
                }
            });

        Schema::table('comments', function (Blueprint $table) {
            $table->text('content')->change();
        });
    }
};
