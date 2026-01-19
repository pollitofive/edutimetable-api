<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function loginToken(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas'], 422);
        }

        $user = $request->user();

        $token = $user->createToken('postman-token', ['*'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function logoutToken(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ok']);
    }

    public function me(Request $request)
    {
        return $request->user();
    }
}
