<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request){
        $fields = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string'
        ]);
        $user = User::where('email', $fields['email'])->first();

        if(!$user || !Hash::check($fields['password'], $user->password)) {
            $response = [
                'message'=>"Adresse email ou mot de passe incorrect",
            ];
            return response($response, 401);
        }

        // Compte désactivé par le super-admin (après vérification du mot de passe :
        // on ne révèle pas l'état d'un compte à qui ne connaît pas ses identifiants)
        if (! $user->access) {
            \Illuminate\Support\Facades\Log::info('Login refusé : compte désactivé', ['user_id' => $user->id]);

            return response([
                'message' => 'Votre compte a été désactivé. Contactez votre établissement ou le support Toollab.',
            ], 403);
        }

        if (! $user->canLogIn()) {
            \Illuminate\Support\Facades\Log::info('Login refusé : compte hors staff', ['user_id' => $user->id]);

            return response([
                'message' => "L'accès à Toollab est réservé aux équipes des écoles.",
            ], 403);
        }

        \App\Support\DailyLogins::record($user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $token = $user->createToken('new_token')->plainTextToken;

        $response = [
            'user'=>$user,
            'token'=>$token
        ];

        return response($response,201);
    }

    public function logout(Request $request){
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        $response = [
            'message'=>"Vous êtes désormais déconnecté !",
        ];
        return response($response, 200);
    }
}
