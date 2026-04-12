<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthService
{
    /**
     * Verify a Google ID token and return structured user data.
     *
     * Uses Google's tokeninfo endpoint which is lightweight and
     * doesn't require the google/apiclient package.
     * For production at scale, switch to google/apiclient for offline verification.
     *
     * @throws \Exception on invalid or expired token
     */
    public function verifyIdToken(string $idToken): array
    {
        $googleClientId = config('services.google.client_id');

        if (empty($googleClientId)) {
            throw new \RuntimeException('Google Client ID is not configured.');
        }

        // Verify token via Google's tokeninfo endpoint
        $response = $this->callGoogleTokenInfo($idToken);

        // Security: Verify the token was issued for OUR app
        if (($response['aud'] ?? '') !== $googleClientId) {
            throw new \Exception('Google token audience mismatch. Token not issued for this app.');
        }

        // Verify issuer
        $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
        if (!in_array($response['iss'] ?? '', $validIssuers)) {
            throw new \Exception('Invalid Google token issuer.');
        }

        // Verify not expired
        if (isset($response['exp']) && $response['exp'] < time()) {
            throw new \Exception('Google token has expired.');
        }

        $email = $response['email'] ?? null;
        $googleId = $response['sub'] ?? null;

        if (!$email || !$googleId) {
            throw new \Exception('Incomplete Google profile. Email and sub claims are required.');
        }

        return [
            'google_id'  => $googleId,
            'email'      => $email,
            'name'       => $response['name'] ?? $response['email'],
            'avatar_url' => $response['picture'] ?? null,
            'raw'        => $response,
        ];
    }

    /**
     * Find or create a user from verified Google data.
     * Handles: new users, existing users (link accounts), and existing social accounts.
     */
    public function findOrCreateUser(array $googleData): User
    {
        // 1. Look for existing social account by provider + provider_id
        $socialAccount = SocialAccount::where('provider', 'google')
            ->where('provider_id', $googleData['google_id'])
            ->with('user')
            ->first();

        if ($socialAccount) {
            // Update profile data in case avatar/email changed
            $socialAccount->update([
                'provider_email' => $googleData['email'],
                'avatar_url'     => $googleData['avatar_url'],
            ]);

            return $socialAccount->user;
        }

        // 2. No social account → look for user with same email
        $user = User::where('email', $googleData['email'])->first();

        if ($user) {
            // Link the Google account to the existing user
            $this->createSocialAccount($user, $googleData);

            Log::info('Google account linked to existing user', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            return $user;
        }

        // 3. Brand new user — create user + social account
        $user = User::create([
            'name'       => $googleData['name'],
            'email'      => $googleData['email'],
            'avatar_url' => $googleData['avatar_url'],
            'password'   => bcrypt(Str::random(32)), // Random, unusable password
            'is_active'  => true,
        ]);

        // Assign default "User" role
        $defaultRole = Role::where('name', 'User')->first();
        if ($defaultRole) {
            $user->roles()->syncWithoutDetaching([$defaultRole->id]);
        }

        $this->createSocialAccount($user, $googleData);

        Log::info('New user created via Google Auth', [
            'user_id' => $user->id,
            'email'   => $user->email,
        ]);

        return $user;
    }

    /**
     * Create a SocialAccount record linking user to their Google identity.
     */
    private function createSocialAccount(User $user, array $googleData): SocialAccount
    {
        return SocialAccount::create([
            'user_id'        => $user->id,
            'provider'       => 'google',
            'provider_id'    => $googleData['google_id'],
            'provider_email' => $googleData['email'],
            'avatar_url'     => $googleData['avatar_url'],
            'provider_data'  => $googleData['raw'],
        ]);
    }

    /**
     * Call Google tokeninfo endpoint to verify and decode the token.
     *
     * @throws \Exception on network failure or invalid token
     */
    private function callGoogleTokenInfo(string $idToken): array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method'  => 'GET',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \Exception('Unable to verify Google token. Network error or token validation service unavailable.');
        }

        $data = json_decode($result, true);

        if (isset($data['error_description'])) {
            throw new \Exception('Invalid Google token: ' . $data['error_description']);
        }

        return $data;
    }
}
