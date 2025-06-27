<?php

namespace App\Observers;

use App\Models\UserInfo;
use App\Models\UserRole;

class UserInfoObserver
{
    /**
     * Handle the UserInfo "created" event.
     */
    public function created(UserInfo $userInfo): void
    {
        //
    }

    /**
     * Handle the UserInfo "updated" event.
     */
    public function updated(UserInfo $userInfo): void
    {
        //
    }

    /**
     * Handle the UserInfo "deleted" event.
     */
    public function deleted(UserInfo $userInfo): void
    {
        //
    }

    /**
     * Handle the UserInfo "restored" event.
     */
    public function restored(UserInfo $userInfo): void
    {
        //
    }

    /**
     * Handle the UserInfo "force deleted" event.
     */
    public function forceDeleted(UserInfo $userInfo): void
    {
        //
    }

    public function updating(UserInfo $userInfo)
    {
        $connectedUserId = auth()->id();

        if ($connectedUserId && $userInfo->user_id) {
            if ($userInfo->isDirty()) {
                UserRole::where('user_id', $userInfo->user_id)
                    ->update([
                        'updated_by' => $connectedUserId,
                        'updated_at' => now()
                    ]);
            }
        }
    }
}
