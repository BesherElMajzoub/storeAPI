<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\OtpSendRequest;
use App\Http\Requests\Api\V1\Auth\OtpVerifyRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    private int $resetTokenTtlMinutes = 60;

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        $defaultRole = Role::query()->where('name', 'User')->first();
        if ($defaultRole) {
            $user->roles()->syncWithoutDetaching([$defaultRole->id]);
        }

        $tokenName = $data['device_name'] ?? 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('roles'),
        ], 'Registered successfully.', 201);
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials.', 401);
        }

        if (!$user->is_active) {
            return $this->error('Account is disabled.', 403);
        }

        Auth::login($user);

        $tokenName = $data['device_name'] ?? 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('roles'),
        ], 'Login successful.');
    }

    public function me(Request $request)
    {
        return $this->success($request->user()->load('roles'), 'Profile fetched.');
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $all = filter_var($request->boolean('all'), FILTER_VALIDATE_BOOLEAN);

        if ($all) {
            $user->tokens()->delete();
        } else {
            $user->currentAccessToken()?->delete();
        }

        return $this->success(null, 'Logged out successfully.');
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $tokenName = $request->input('device_name', 'auth_token');

        $newToken = $user->createToken($tokenName)->plainTextToken;
        $user->currentAccessToken()?->delete();

        return $this->success([
            'access_token' => $newToken,
            'token_type' => 'Bearer',
        ], 'Token refreshed.');
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user) {
            $token = $this->issuePasswordResetToken($user);
            Log::info('Password reset token issued', [
                'email' => $user->email,
                'token' => $token,
            ]);
        }

        return $this->success(null, 'If the email exists, a reset token was sent.');
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();
        if (!$user) {
            return $this->error('Invalid token or email.', 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();
        if (!$record) {
            return $this->error('Invalid token or email.', 422);
        }

        $expiresAt = now()->subMinutes($this->resetTokenTtlMinutes);
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->lt($expiresAt)) {
            return $this->error('Reset token expired.', 422);
        }

        if (!hash_equals($record->token, hash('sha256', $data['token']))) {
            return $this->error('Invalid token or email.', 422);
        }

        $user->password = $data['password'];
        $user->save();

        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return $this->success(null, 'Password reset successfully.');
    }

    public function sendOtp(OtpSendRequest $request, OtpService $otpService)
    {
        $data = $request->validated();
        $purpose = $data['purpose'] ?? 'password_reset';
        $channel = $data['channel'] ?? 'email';
        $email = $data['email'];

        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $result = $otpService->send($email, $purpose, $channel);
            if ($result['status'] === 'cooldown' || $result['status'] === 'daily_limit') {
                return $this->error('OTP rate limit exceeded. Try again later.', 429);
            }
        }

        return $this->success(null, 'If the email exists, an OTP was sent.');
    }

    public function verifyOtp(OtpVerifyRequest $request, OtpService $otpService)
    {
        $data = $request->validated();
        $purpose = $data['purpose'] ?? 'password_reset';
        $channel = $data['channel'] ?? 'email';
        $email = $data['email'];
        $otp = $data['otp'];

        $result = $otpService->verify($email, $purpose, $otp, $channel);
        if ($result['status'] !== 'verified') {
            return $this->error('Invalid or expired OTP.', 422);
        }

        $user = User::query()->where('email', $email)->first();
        if (!$user) {
            return $this->error('Invalid or expired OTP.', 422);
        }

        $token = $this->issuePasswordResetToken($user);

        return $this->success([
            'reset_token' => $token,
            'token_type' => 'Reset',
        ], 'OTP verified.');
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
             return $this->error('Invalid token.', 400);
        }
        
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Google User', 'password' => Hash::make(str()->random(16))]
        );

        $token = $user->createToken('google_auth_token')->plainTextToken;

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 'Login successful.');
    }

    private function issuePasswordResetToken(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        return $token;
    }

    private function success($data, string $message, int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    private function error(string $message, int $status, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
