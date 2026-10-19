<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            // customer_cancel | request_accepted | admin_cancel | admin_refund | admin_retry | manual | reconciliation | system
            $table->string('trigger', 24);
            // stripe | manual | reconciliation (found in Stripe, made outside the shop)
            $table->string('channel', 16);
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('usd');
            // Stripe: requested -> pending -> succeeded | failed. Manual: recorded | voided.
            $table->string('status', 16);
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('stripe_idempotency_key')->nullable()->unique();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('next_action', 24)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('method', 16)->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            // Rule R2: one refund in progress per order. MySQL has no partial
            // unique index, so a generated column is NULL (and ignored by the
            // unique index) unless the attempt is still open.
            $table->unsignedBigInteger('open_order_id')->nullable()
                ->virtualAs("CASE WHEN status IN ('requested', 'pending') THEN order_id END");

            $table->unique(['order_id', 'attempt_no']);
            $table->unique('open_order_id');
            $table->index(['status', 'created_at']);
        });

        Schema::create('refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('refund_attempts')->cascadeOnDelete();
            $table->string('event', 32);
            // system | stripe | admin:{id} | customer:{id}
            $table->string('actor', 32);
            // A webhook delivered twice is recorded once.
            $table->string('stripe_event_id')->nullable()->unique();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            // stripe | manual | mixed: how the money went back, calculated from the attempts.
            $table->string('refunded_via', 16)->nullable()->after('refunded_at');
        });

        $this->backfillLegacyOrders();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_via');
        });
        Schema::dropIfExists('refund_events');
        Schema::dropIfExists('refund_attempts');
    }

    /**
     * Orders refunded or failed before the record existed get one attempt, so
     * the history is complete and the next attempt number continues the old
     * idempotency keys (`refund-order-12`, `refund-order-12-1`, ...).
     */
    private function backfillLegacyOrders(): void
    {
        DB::table('orders')
            ->whereNotNull('stripe_payment_intent_id')
            ->whereIn('refund_status', ['succeeded', 'failed'])
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $succeeded = $order->refund_status === 'succeeded';
                    $cents = (int) round(((float) ($succeeded && (float) $order->refunded_amount > 0 ? $order->refunded_amount : $order->total)) * 100);
                    $now = now();

                    $attemptId = DB::table('refund_attempts')->insertGetId([
                        'order_id' => $order->id,
                        'attempt_no' => max(1, (int) $order->refund_failures),
                        'trigger' => 'system',
                        'channel' => 'stripe',
                        'amount_cents' => $cents,
                        'currency' => (string) config('services.stripe.currency', 'usd'),
                        'status' => $succeeded ? 'succeeded' : 'failed',
                        'failure_code' => $succeeded ? null : $order->refund_failure_code,
                        'failure_message' => $succeeded ? null : $order->refund_failure_message,
                        'created_at' => $order->refund_failed_at ?? $order->refunded_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('refund_events')->insert([
                        'order_id' => $order->id,
                        'attempt_id' => $attemptId,
                        'event' => 'backfilled',
                        'actor' => 'system',
                        'details' => json_encode(['source' => 'orders table before the refund record existed']),
                        'created_at' => $now,
                    ]);
                }
            });
    }
};
