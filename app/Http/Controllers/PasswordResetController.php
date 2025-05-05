<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $this->sendResponse($status, 'Un lien de réinitialisation du mot de passe a été envoyé à votre adresse email.', 'Une erreur est survenue lors de l\'envoi du lien de réinitialisation.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $this->updateUserPassword($user, $password);
            }
        );

        return $this->sendResponse($status, 'Votre mot de passe a été réinitialisé avec succès.', 'Une erreur est survenue lors de la réinitialisation du mot de passe.');
    }

    public function checkResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        $passwordReset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset || !Hash::check($request->token, $passwordReset->token) || $this->isTokenExpired($passwordReset->created_at)) {
            return response()->json(['message' => 'Votre lien de réinitialisation est invalide ou a expiré.'], 422);
        }

        return response()->json(['message' => 'Lien de réinitialisation valide.'], 200);
    }

    private function updateUserPassword($user, $password)
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function isTokenExpired($createdAt)
    {
        $expirationTime = Carbon::parse($createdAt)->addMinutes(config('auth.passwords.users.expire', 60));
        return $expirationTime->isPast();
    }

    private function sendResponse($status, string $successMessage, string $errorMessage)
    {
        return $status === Password::RESET_LINK_SENT || $status === Password::PASSWORD_RESET
            ? response()->json(['message' => $successMessage])
            : response()->json(['message' => $errorMessage], 500);
    }
}
