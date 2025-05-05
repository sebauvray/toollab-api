<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function register(Request $request){
        $fields = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|string|unique:users,email',
            'password' => 'required|string',
            'access' => 'required|boolean',
        ]);

        $user = new User();
        $user->first_name = $fields['first_name'];
        $user->last_name = $fields['last_name'];
        $user->email = $fields['email'];
        $user->password = bcrypt($fields['password']);
        $user->access = $fields['access'];
        $user->save();
        $token = $user->createToken('new_token')->plainTextToken;

        $response = [
            'user'=>$user,
            'token'=>$token
        ];

        return response($response,201);

    }

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

        $token = $user->createToken('new_token')->plainTextToken;

        $response = [
            'user'=>$user,
            'token'=>$token
        ];

        return response($response,201);
    }

    public function logout(Request $request){
        auth()->user()->tokens()->delete();
        $response = [
            'message'=>"Vous êtes désormais déconnecté !",
        ];
        return response($response, 200);
    }
}
