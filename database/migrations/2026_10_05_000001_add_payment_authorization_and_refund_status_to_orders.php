<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'authorized' = the card hold succeeded but the money is not captured yet.
        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('unpaid', 'authorized', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid'");

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('authorized_at')->nullable()->after('stripe_payment_intent_id');
            // Tracks returning money for a cancelled order independently of order.status:
            // released = card hold cancelled, succeeded = captured money refunded.
            $table->enum('refund_status', ['none', 'pending', 'released', 'succeeded', 'failed'])
                ->default('none')->after('refunded_amount');
            // Set (and committed) before calling EasyPost so a concurrent
            // customer cancel sees the label purchase and is refused.
            $table->timestamp('fulfillment_started_at')->nullable()->after('shipped_at');
            $table->index(['payment_status', 'status', 'authorized_at']);
        });

        DB::table('orders')->where('payment_status', 'refunded')->update(['refund_status' => 'succeeded']);
    }

    public function down(): void
    {
        DB::table('orders')->where('payment_status', 'authorized')->update(['payment_status' => 'paid']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'status', 'authorized_at']);
            $table->dropColumn(['authorized_at', 'refund_status', 'fulfillment_started_at']);
        });

        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('unpaid', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid'");
    }
};
