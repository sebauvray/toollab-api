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
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'requires_profile' => blank($user->first_name) || blank($user->last_name),
            ]
        ]);
    }

    /**
     * Liste les invitations d'école en attente de l'utilisateur connecté.
     * Une invitation est "en attente" tant que l'adhésion n'a pas été acceptée
     * (accepted_at null) : l'école ne voit alors pas le nom de l'utilisateur.
     */
    public function myInvitations(Request $request)
    {
        $user = $request->user();

        $pending = $user->roles()
            ->whereIn('roleable_type', ['school', School::class])
            ->whereNull('accepted_at')
            ->with(['role', 'roleable'])
            ->get();

        return $pending
            ->groupBy('roleable_id')
            ->map(function ($group) {
                return [
                    'school_id' => $group->first()->roleable_id,
                    'school_name' => $group->first()->roleable?->name,
                    'roles' => $group->map(fn ($ur) => $ur->role?->name)->filter()->values(),
                ];
            })
            ->values();
    }

    /**
     * Acceptation explicite d'une invitation, depuis l'application.
     * Le nom de l'utilisateur devient alors visible par l'école concernée.
     */
    public function acceptInvitation(Request $request)
    {
        $request->validate(['school_id' => 'required|integer']);

        $user = $request->user();

        $updated = $user->roles()
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $request->school_id)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now()]);

        if (!$updated) {
            return response()->json([
                'message' => 'Aucune invitation en attente pour cette école'
            ], 404);
        }

        InvitationToken::where('email', $user->email)
            ->where('school_id', $request->school_id)
            ->delete();

        $school = School::find($request->school_id);

        return response()->json([
            'message' => 'Vous avez rejoint ' . ($school?->name ?? 'l\'école') . '.',
        ]);
    }

    /**
     * Refus d'une invitation : l'accès à l'école est retiré (les adhésions en
     * attente sont supprimées).
     */
    public function declineInvitation(Request $request)
    {
        $request->validate(['school_id' => 'required|integer']);

        $user = $request->user();

        $deleted = $user->roles()
            ->whereIn('roleable_type', ['school', School::class])
            ->where('roleable_id', $request->school_id)
            ->whereNull('accepted_at')
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Aucune invitation en attente pour cette école'
            ], 404);
        }

        InvitationToken::where('email', $user->email)
            ->where('school_id', $request->school_id)
            ->delete();

        return response()->json([
            'message' => 'Invitation refusée.',
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

        // En activant son compte via le lien d'une école, l'utilisateur accepte
        // l'invitation de CETTE école uniquement (acceptation explicite et ciblée).
        // Les éventuelles autres invitations restent en attente (bandeau in-app).
        if ($token->school_id) {
            $user->roles()
                ->whereIn('roleable_type', ['school', School::class])
                ->where('roleable_id', $token->school_id)
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]);
        }

        $user->tokens()->delete();

        $token->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été défini avec succès'
        ]);
    }
}
