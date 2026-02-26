<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        $token = $user->createToken('pos-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}


// class AuthController extends Controller
// {
//     // 1. Connexion (Login)
//     public function login(Request $request)
//     {
//         $request->validate([
//             'email' => 'required|email',
//             'password' => 'required',
//         ]);

//         $user = User::where('email', $request->email)->first();

//         // Vérification du mot de passe
//         if (! $user || ! Hash::check($request->password, $user->password)) {
//             return response()->json([
//                 'message' => 'Les identifiants sont incorrects.'
//             ], 401);
//         }

//         // Création du Token (Permet de rester connecté)
//         // On nomme le token avec le rôle de l'utilisateur pour le suivi
//         $token = $user->createToken($user->role)->plainTextToken;

//         return response()->json([
//             'message' => 'Connexion réussie',
//             'access_token' => $token,
//             'token_type' => 'Bearer',
//             'user' => [
//                 'id' => $user->id,
//                 'name' => $user->name,
//                 'email' => $user->email,
//                 'role' => $user->role,
//                 'department' => $user->department, // CRUCIAL pour le Frontend
//             ]
//         ]);
//     }

//     // 2. Déconnexion (Logout)
//     public function logout(Request $request)
//     {
//         // Supprime le token actuel (révoque l'accès)
//         $request->user()->currentAccessToken()->delete();

//         return response()->json(['message' => 'Déconnexion réussie']);
//     }

//     // 3. Profil Utilisateur (Me)
//     public function me(Request $request)
//     {
//         return response()->json($request->user());
//     }
// }
