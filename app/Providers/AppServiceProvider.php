<?php

namespace App\Providers;

use App\Contracts\LocationServiceInterface;
use App\Events\WishlistItemAdded;
use App\Events\WishlistItemRemoved;
use App\Listeners\RecordWishlistEvent;
use App\Models\Address;
use App\Models\Order;
use App\Models\Review;
use App\Observers\OrderObserver;
use App\Observers\ReviewObserver;
use App\Policies\AddressPolicy;
use App\Policies\ReviewPolicy;
use App\Services\GeoapifyService;
use App\Services\GooglePlacesService;
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
        $this->app->singleton(LocationServiceInterface::class, function ($app) {
            $provider = config('services.location_provider', 'geoapify');
            if ($provider === 'google') {
                return new GooglePlacesService;
            }

            return new GeoapifyService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ─── Rate Limiters ────────────────────────────────────────────────────────
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(10)->by('login-ip|'.$request->ip()),
                Limit::perMinute(5)->by('login-account|'.$email),
            ];
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('forgot-ip|'.$request->ip()),
                Limit::perHour(5)->by('forgot-account|'.$email),
            ];
        });

        RateLimiter::for('otp', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('otp-ip|'.$request->ip()),
                Limit::perMinute(3)->by('otp-account|'.$email),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('reset-ip|'.$request->ip()),
                Limit::perHour(5)->by('reset-account|'.$email),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(120)->by('api|'.$key);
        });

        RateLimiter::for('order-tracking', function (Request $request) {
            $fingerprint = hash('sha256', Str::lower(
                trim((string) $request->input('order_number')).'|'.trim((string) $request->input('email'))
            ));

            return [
                Limit::perMinute(5)->by('tracking-ip|'.$request->ip()),
                Limit::perMinute(3)->by('tracking-query|'.$fingerprint),
            ];
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
        Order::observe(OrderObserver::class);

        // ─── Event Listeners ──────────────────────────────────────────────────────
        $listener = new RecordWishlistEvent;
        Event::listen(WishlistItemAdded::class, [$listener, 'handleAdded']);
        Event::listen(WishlistItemRemoved::class, [$listener, 'handleRemoved']);
    }
}
