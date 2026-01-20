<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Default role?
        // $user->roles()->attach(Role::where('name', 'User')->first());

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    // Google Login - simplified mock/stub logic as we don't have Socialite installed
    public function googleLogin(Request $request)
    {
        $request->validate(['token' => 'required']);
        
        // In real app: Validate token with Google, get email/name
        // $googleUser = Socialite::driver('google')->userFromToken($request->token);
        
        // Mocking behavior
        $email = $request->input('email'); // Should come from token
        if (!$email) {
             return response()->json(['message' => 'Invalid token'], 400);
        }
        
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Google User', 'password' => Hash::make(str()->random(16))]
        );

        $token = $user->createToken('google_auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function sendOtp(Request $request)
    {
        $request->validate(['phone' => 'required']);
        // Generate OTP, save to cache, send SMS
        // Log::info("OTP for {$request->phone}: 1234");
        return response()->json(['message' => 'OTP sent (Simulation: 1234)']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'otp' => 'required'
        ]);

        if ($request->otp !== '1234') {
            return response()->json(['message' => 'Invalid OTP'], 400);
        }

        $user = User::firstOrCreate(
            ['email' => $request->phone . '@phone.com'], // Placeholder email
            ['name' => 'Phone User', 'password' => Hash::make(str()->random(16))]
        );

        $token = $user->createToken('phone_auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }
}
