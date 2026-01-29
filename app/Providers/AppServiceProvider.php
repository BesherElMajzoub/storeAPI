<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            return Limit::perMinute(3)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            return Limit::perMinute(3)->by($email . '|' . $request->ip());
        });

        \Illuminate\Support\Facades\Gate::define('admin-access', function ($user) {
            // Logic: User must have a role that is NOT just 'User'
            // Or strictly: Admin, Owner, Manager, Support
            // Assuming 'User' is the default customer role.
            // Since we implemented hasRole in User model:
            return $user->roles()->whereIn('name', ['Admin', 'Owner', 'Manager', 'Support'])->exists();
        });
    }
}
