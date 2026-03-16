<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use App\Models\Address;
use App\Policies\AddressPolicy;

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
            return $user->roles()->whereIn('name', ['Admin', 'Owner', 'Manager', 'Support'])->exists();
        });

        // Register Policies
        Gate::policy(Address::class, AddressPolicy::class);
    }
}
