<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\GoogleLoginRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\OtpSendRequest;
use App\Http\Requests\Api\V1\Auth\OtpVerifyRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Mail\ResetPasswordMail;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleAuthService;
use App\Services\OtpService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    use ApiResponseTrait;

    private int $resetTokenTtlMinutes = 60;

    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'User Register',
        description: 'Register a new user',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                new OA\Property(property: 'phone', type: 'string', example: '+123456789', nullable: true),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                new OA\Property(property: 'device_name', type: 'string', example: 'web', nullable: true),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'User registered',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
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

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'User Login',
        description: 'Authenticate a user and return a token',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@store.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                new OA\Property(property: 'device_name', type: 'string', example: 'web', nullable: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful login',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/ErrorResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials.', 401);
        }

        if (! $user->is_active) {
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

    #[OA\Get(
        path: '/api/v1/auth/me',
        summary: 'Get Authenticated User',
        description: "Returns the currently authenticated user's profile",
        security: [['bearerAuth' => []]],
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'Profile fetched',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/User'),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/ErrorResponse')]
    public function me(Request $request)
    {
        return $this->success($request->user()->load('roles'), 'Profile fetched.');
    }

    #[OA\Put(
        path: '/api/v1/auth/me',
        summary: 'Update My Profile',
        description: "Update the authenticated user's profile information. All fields are optional.",
        security: [['bearerAuth' => []]],
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                // new OA\Property(property: "email",    type: "string",  format: "email", example: "john@example.com"),
                new OA\Property(property: 'phone', type: 'string', example: '+123456789', nullable: true),
                // new OA\Property(property: "password", type: "string",  format: "password", example: "newPassword123"),
                // new OA\Property(property: "password_confirmation", type: "string", format: "password", example: "newPassword123"),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Profile updated successfully',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Profile updated successfully.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/ErrorResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        // Only update fields that were actually sent in the request
        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }
        if (isset($data['phone'])) {
            $user->phone = $data['phone'] ?? null;
        }
        if (isset($data['password'])) {
            $user->password = $data['password'];
        }  // auto-hashed by cast

        $user->save();

        return $this->success($user->fresh()->load('roles'), 'Profile updated successfully.');
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'User Logout',
        description: 'Log out the authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'all', type: 'boolean', description: 'If true, logs out from all devices', default: false),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Logged out successfully')]
    #[OA\Response(response: 401, ref: '#/components/responses/ErrorResponse')]
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

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        summary: 'Refresh Token',
        description: 'Refresh the current authentication token',
        security: [['bearerAuth' => []]],
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'device_name', type: 'string', example: 'web'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Token refreshed',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/ErrorResponse')]
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

    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        summary: 'Forgot Password',
        description: 'Send a password reset token to the given email',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Reset token sent')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        // 1. Silent Success standard (don't reveal if user exists)
        if (! $user) {
            // Optional: Fake delay to prevent timing attacks
            usleep(random_int(100000, 300000));

            return $this->success(null, 'If the email exists, a reset token was sent.');
        }

        try {
            // 2. Generate Token
            $token = $this->issuePasswordResetToken($user);

            // 3. SEND THE EMAIL (The missing part)
            // Using a queueable mailable ensures this doesn't hang the API
            Mail::to($user->email)->later(now()->addSeconds(5), new ResetPasswordMail($token, $user->email));
            Log::info('Password reset link sent', ['email' => $user->email]);

        } catch (\Throwable $e) {
            // 4. Log validation errors or mail failures, but keep API response 200
            Log::error('Password reset failed', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success(null, 'If the email exists, a reset token was sent.');
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password',
        summary: 'Reset Password',
        description: 'Reset user password using token',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'token', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@store.com'),
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'password', type: 'string', format: 'password123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password123'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Password reset successfully')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user) {
            return $this->error('Invalid token or email.', 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();
        if (! $record) {
            return $this->error('Invalid token or email.', 422);
        }

        $expiresAt = now()->subMinutes($this->resetTokenTtlMinutes);
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->lt($expiresAt)) {
            return $this->error('Reset token expired.', 422);
        }

        if (! hash_equals($record->token, hash('sha256', $data['token']))) {
            return $this->error('Invalid token or email.', 422);
        }

        $user->password = $data['password'];
        $user->save();

        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return $this->success(null, 'Password reset successfully.');
    }

    #[OA\Post(
        path: '/api/v1/auth/otp/send',
        summary: 'Send OTP',
        description: "Send OTP to user's email",
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'purpose', type: 'string', default: 'password_reset'),
                new OA\Property(property: 'channel', type: 'string', default: 'email'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'OTP sent')]
    #[OA\Response(response: 429, ref: '#/components/responses/ErrorResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function sendOtp(OtpSendRequest $request, OtpService $otpService)
    {
        $data = $request->validated();
        $purpose = $data['purpose'] ?? 'password_reset';
        $channel = $data['channel'] ?? 'email';
        $email = $data['email'];

        $user = User::query()->where('email', $email)->first();
        if ($user) {
            Log::info('OTP send: before');
            $result = $otpService->send($email, $purpose, $channel);
            Log::info('OTP send: after', $result);
            if ($result['status'] === 'cooldown' || $result['status'] === 'daily_limit') {
                return $this->error('OTP rate limit exceeded. Try again later.', 429);
            }
        }

        return $this->success(null, 'If the email exists, an OTP was sent.');
    }

    #[OA\Post(
        path: '/api/v1/auth/otp/verify',
        summary: 'Verify OTP',
        description: 'Verify OTP sent to user',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'otp'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'otp', type: 'string'),
                new OA\Property(property: 'purpose', type: 'string', default: 'password_reset'),
                new OA\Property(property: 'channel', type: 'string', default: 'email'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'OTP verified',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'reset_token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Reset'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
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
        if (! $user) {
            return $this->error('Invalid or expired OTP.', 422);
        }

        $token = $this->issuePasswordResetToken($user);

        return $this->success([
            'reset_token' => $token,
            'token_type' => 'Reset',
        ], 'OTP verified.');
    }

    // Google Login — verifies Google ID token server-side, no mock
    #[OA\Post(
        path: '/api/v1/auth/google',
        summary: 'Google Login',
        description: 'Login or register via a verified Google ID token. Handles account linking automatically.',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['id_token'],
            properties: [
                new OA\Property(property: 'id_token', type: 'string', description: 'Google ID token from client-side Google Sign-In'),
                new OA\Property(property: 'device_name', type: 'string', example: 'web', nullable: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Login successful',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        new OA\Property(property: 'is_new_user', type: 'boolean'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Invalid Google token')]
    #[OA\Response(response: 403, description: 'Account is disabled')]
    #[OA\Response(response: 503, description: 'Cannot reach Google verification service')]
    public function googleLogin(GoogleLoginRequest $request, GoogleAuthService $googleAuthService)
    {
        try {
            // 1. Verify the ID token with Google (server-side — never trust client-sent email)
            $googleData = $googleAuthService->verifyIdToken($request->id_token);

            // 2. Find or create user (handles linking existing accounts transparently)
            $isNewUser = !User::where('email', $googleData['email'])->exists();
            $user      = $googleAuthService->findOrCreateUser($googleData);

            // 3. Check account status
            if (!$user->is_active) {
                return $this->error('Account is disabled.', 403);
            }

            // 4. Update last login metadata
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            // 5. Issue Sanctum token
            $tokenName = $request->input('device_name', 'google_auth');
            $token     = $user->createToken($tokenName)->plainTextToken;

            Log::info('Google Auth login', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'is_new'  => $isNewUser,
            ]);

            return $this->success([
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user->load('roles'),
                'is_new_user'  => $isNewUser,
            ], $isNewUser ? 'Account created successfully.' : 'Login successful.');

        } catch (\RuntimeException $e) {
            Log::error('Google Auth misconfiguration', ['error' => $e->getMessage()]);
            return $this->error('Authentication service is unavailable.', 503);
        } catch (\Exception $e) {
            Log::warning('Google Auth failed', ['error' => $e->getMessage(), 'ip' => $request->ip()]);
            return $this->error('Invalid or expired Google token.', 401);
        }
    }

    private function issuePasswordResetToken(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        return $token;
    }
}
