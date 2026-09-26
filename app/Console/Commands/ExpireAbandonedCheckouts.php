<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\StripeCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'orders:expire-abandoned-checkouts';

    protected $description = 'Expire stale Stripe Checkout Sessions and release abandoned reservations.';

    public function handle(StripeCheckoutService $stripe): int
    {
        $minutes = max(30, min(1440, (int) config('services.stripe.checkout_expires_minutes', 30)));
        $cutoff = now()->subMinutes($minutes + 5);

        Order::query()->where('status', 'pending_payment')->where('payment_status', 'unpaid')
            ->whereNotNull('stripe_session_id')->where('created_at', '<=', $cutoff)
            ->orderBy('id')->chunkById(100, function ($orders) use ($stripe): void {
                foreach ($orders as $candidate) {
                    try {
                        $session = $stripe->retrieveCheckoutSession($candidate->stripe_session_id);
                        if ($session->status === 'complete') {
                            Log::warning('Abandoned checkout is complete; webhook owns payment confirmation.', ['order_id' => $candidate->id]);

                            continue;
                        }
                        $sessionExpired = $session->status === 'expired'
                            || ($session->status === 'open'
                                && isset($session->expires_at)
                                && (int) $session->expires_at <= now()->timestamp);

                        if (! $sessionExpired) {
                            continue;
                        }

                        if ($session->status === 'open') {
                            $stripe->expireCheckoutSession($candidate->stripe_session_id);
                        }
                        DB::transaction(function () use ($candidate): void {
                            $order = Order::query()->whereKey($candidate->id)->lockForUpdate()->first();
                            if ($order
                                && $order->getRawOriginal('status') === 'pending_payment'
                                && $order->getRawOriginal('payment_status') === 'unpaid'
                                && $order->stripe_session_id === $candidate->stripe_session_id) {
                                $order->update(['status' => 'cancelled', 'payment_status' => 'failed', 'cancelled_at' => now()]);
                            }
                        });
                    } catch (\Throwable $e) {
                        Log::warning('Unable to expire abandoned checkout; will retry.', ['order_id' => $candidate->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
