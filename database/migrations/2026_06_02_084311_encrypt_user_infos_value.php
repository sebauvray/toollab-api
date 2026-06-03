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
        Schema::table('user_infos', function (Blueprint $table) {
            $table->mediumText('value')->nullable()->change();
        });

        DB::table('user_infos')
            ->orderBy('id')
            ->chunkById(200, function ($userInfos) {
                foreach ($userInfos as $userInfo) {
                    if ($userInfo->value === null) {
                        continue;
                    }

                    try {
                        Crypt::decryptString($userInfo->value);
                        continue;
                    } catch (DecryptException) {
                        // Existing plaintext value, encrypt it below.
                    }

                    DB::table('user_infos')
                        ->where('id', $userInfo->id)
                        ->update(['value' => Crypt::encryptString($userInfo->value)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('user_infos')
            ->orderBy('id')
            ->chunkById(200, function ($userInfos) {
                foreach ($userInfos as $userInfo) {
                    if ($userInfo->value === null) {
                        continue;
                    }

                    try {
                        $value = Crypt::decryptString($userInfo->value);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('user_infos')
                        ->where('id', $userInfo->id)
                        ->update(['value' => $value]);
                }
            });

        Schema::table('user_infos', function (Blueprint $table) {
            $table->text('value')->nullable(false)->change();
        });
    }
};
