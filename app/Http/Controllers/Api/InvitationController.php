<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvitationToken;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class InvitationController extends Controller
{

    public function checkInvitationToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Les données fournies sont incorrectes',
                'errors' => $validator->errors()
            ], 422);
        }

        $token = InvitationToken::with('school')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$token) {
            return response()->json([
                'message' => 'Le lien d\'invitation est invalide ou a expiré'
            ], 404);
        }

        $user = User::where('email', $request->email)->first();

        return response()->json([
            'message' => 'Le token d\'invitation est valide',
            // 'accept' : utilisateur existant qui accepte de rejoindre une école précise
            // (il doit se connecter). 'activate' : nouveau compte à activer (mot de passe).
            'mode' => $token->school_id ? 'accept' : 'activate',
            'school_name' => $token->school?->name,
            'user' => [
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'requires_profile' => blank($user->first_name) || blank($user->last_name),
            ]
        ]);
    }

    /**
     * Acceptation d'une invitation par un utilisateur DÉJÀ existant.
     * Endpoint authentifié : l'acceptation (et donc la révélation du nom à
     * l'école invitante) n'a lieu qu'une fois l'utilisateur connecté, après avoir
     * suivi le lien reçu par email.
     */
    public function acceptInvitation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Les données fournies sont incorrectes',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        $token = InvitationToken::with('school')
            ->where('token', $request->token)
            ->where('email', $user->email)
            ->whereNotNull('school_id')
            ->where('expires_at', '>', now())
            ->first();

        if (!$token) {
            return response()->json([
                'message' => 'L\'invitation est invalide ou a expiré'
            ], 404);
        }

        $user->roles()
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $token->school_id)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now()]);

        $schoolName = $token->school?->name;

        $token->delete();

        return response()->json([
            'message' => $schoolName
                ? 'Vous avez rejoint ' . $schoolName . '.'
                : 'Invitation acceptée.',
            'school_name' => $schoolName,
        ]);
    }

    public function setPassword(Request $request)
    {
        $rules = [
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ];

        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser && (blank($existingUser->first_name) || blank($existingUser->last_name))) {
            $rules['first_name'] = 'required|string|max:255';
            $rules['last_name'] = 'required|string|max:255';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Les données fournies sont incorrectes',
                'errors' => $validator->errors()
            ], 422);
        }

        $token = InvitationToken::where('email', $request->email)
            ->where('token', $request->token)
            ->where('expires_at', '>', now())
            ->first();

        $user = User::where('email', $request->email)->first();

        if (!$token || !$user) {
            return response()->json([
                'message' => 'Le lien d\'invitation est invalide ou a expiré'
            ], 404);
        }

        if (blank($user->first_name) || blank($user->last_name)) {
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
        }
        $user->password = Hash::make($request->password);
        $user->save();

        // En activant son compte, l'utilisateur accepte les invitations en attente :
        // son nom devient visible par les écoles qui l'ont invité.
        $user->roles()->whereNull('accepted_at')->update(['accepted_at' => now()]);

        $user->tokens()->delete();

        $token->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été défini avec succès'
        ]);
    }
}
