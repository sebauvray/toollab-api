<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserRole;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        //
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        //
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }

    public function updating(User $user)
    {
        $connectedUserId = auth()->id();

        if ($connectedUserId && $user->id) {
            if ($user->isDirty()) {

                UserRole::where('user_id', $user->id)
                    ->update([
                        'updated_by' => $connectedUserId,
                        'updated_at' => now()
                    ]);

            }
        }
    }
}
