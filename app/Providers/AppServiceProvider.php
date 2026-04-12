<?php

namespace App\Providers;

use App\Events\WishlistItemAdded;
use App\Events\WishlistItemRemoved;
use App\Listeners\RecordWishlistEvent;
use App\Models\Address;
use App\Models\Review;
use App\Observers\ReviewObserver;
use App\Policies\AddressPolicy;
use App\Policies\ReviewPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        // ─── Rate Limiters ────────────────────────────────────────────────────────
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

        // ─── Gates ────────────────────────────────────────────────────────────────
        Gate::define('admin-access', function ($user) {
            return $user->roles()->whereIn('name', ['Admin', 'Owner', 'Manager', 'Support'])->exists();
        });

        // ─── Policies ─────────────────────────────────────────────────────────────
        Gate::policy(Address::class, AddressPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);

        // ─── Observers ────────────────────────────────────────────────────────────
        Review::observe(ReviewObserver::class);

        // ─── Event Listeners ──────────────────────────────────────────────────────
        $listener = new RecordWishlistEvent();
        Event::listen(WishlistItemAdded::class,   [$listener, 'handleAdded']);
        Event::listen(WishlistItemRemoved::class, [$listener, 'handleRemoved']);
    }
}
