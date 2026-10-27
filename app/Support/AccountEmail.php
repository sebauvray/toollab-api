<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class AccountEmail
{
    /** Les formulaires métier ne changent jamais l'identifiant d'un compte de connexion. */
    public static function validateChange(User $user, ?string $email): void
    {
        if ($email === null || $email === $user->email) {
            return;
        }

        $reserved = array_map(fn ($value) => strtolower($value), config('toollab.super_admin_emails', []));
        if ($user->canLogIn() || in_array(strtolower($email), $reserved, true)) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse ne peut pas être modifiée depuis ce formulaire.',
            ]);
        }
    }
}
