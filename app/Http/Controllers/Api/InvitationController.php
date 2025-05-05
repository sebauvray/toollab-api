<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvitationToken;
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

        $token = InvitationToken::where('email', $request->email)
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
            'user' => [
                'email' => $user->email,
                'name' => $user->first_name . ' ' . $user->last_name
            ]
        ]);
    }

    public function setPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

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

        if (!$token) {
            return response()->json([
                'message' => 'Le lien d\'invitation est invalide ou a expiré'
            ], 404);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        $token->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été défini avec succès'
        ]);
    }
}
