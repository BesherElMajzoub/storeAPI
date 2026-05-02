<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Laravel\Telescope\EntryType;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Uncomment to enable dark mode
        // Telescope::night();

        $this->hideSensitiveRequestDetails();
        $this->configureFilter();
        $this->configureTags();
    }

    // ─── Filter: ما الذي يتم تسجيله ────────────────────────────────────────────
    protected function configureFilter(): void
    {
        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            // في local: سجّل كل شيء
            if ($isLocal) {
                return true;
            }

            // في staging / production: سجّل فقط ما هو مهم
            return $entry->isReportableException()   // استثناءات قابلة للرفع
                || $entry->isFailedRequest()          // طلبات فاشلة
                || $entry->isFailedJob()              // jobs فاشلة
                || $entry->isScheduledTask()          // scheduled tasks
                || $entry->hasMonitoredTag()          // entries ذات tags محددة
                || ($entry->type === EntryType::QUERY  // استعلامات بطيئة (> 500ms)
                    && isset($entry->content['slow'])
                    && $entry->content['slow'] === true);
        });
    }

    // ─── Tags تلقائية لربط الـ entries بالـ users ───────────────────────────────
    protected function configureTags(): void
    {
        Telescope::tag(function (IncomingEntry $entry) {
            // Guard against infinite recursion:
            // auth()->check() triggers a DB query → Telescope records it →
            // calls this tag callback again → auth()->check() → ∞ loop.
            // The static flag breaks the cycle.
            static $tagging = false;

            if ($tagging) {
                return [];
            }

            $tagging = true;

            try {
                if (auth()->check()) {
                    return ['user:' . auth()->id()];
                }
            } catch (\Throwable) {
                // Silently ignore — auth may not be available yet
            } finally {
                $tagging = false;
            }

            return [];
        });
    }

    // ─── إخفاء البيانات الحساسة ─────────────────────────────────────────────────
    protected function hideSensitiveRequestDetails(): void
    {
        // في local: لا نخفي شيئاً لتسهيل الـ debugging
        if ($this->app->environment('local')) {
            return;
        }

        // في غير local: إخفاء البيانات الحساسة من الـ requests
        Telescope::hideRequestParameters([
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'otp',
            'token',
            '_token',
            'secret',
            'card_number',
            'cvv',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',    // إخفاء Bearer tokens في staging/production
        ]);
    }

    // ─── Gate: من يمكنه دخول Telescope Dashboard ────────────────────────────────
    /**
     * تعمل هذه الـ gate فقط في بيئات غير local.
     * في local — يمكن للجميع الدخول.
     * في staging/production — فقط من يمتلك أدوار معينة.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user) {
            // السماح فقط للمستخدمين الذين لديهم أدوار إدارية
            return $user->roles()
                ->whereIn('name', ['Admin', 'Owner', 'Manager'])
                ->exists();
        });
    }
}
