<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add pending_payment to status enum and new columns
            // We use DB::statement because Laravel can't alter ENUM directly in MySQL
            \DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','pending_payment','processing','shipped','delivered','cancelled','refunded') DEFAULT 'pending'");

            if (!Schema::hasColumn('orders', 'stripe_session_id')) {
                $table->string('stripe_session_id')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('orders', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id')->nullable()->after('stripe_session_id');
            }
            if (!Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('stripe_payment_intent_id');
            }
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('orders', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_session_id',
                'stripe_payment_intent_id',
                'paid_at',
                'cancelled_at',
                'refunded_at',
            ]);

            \DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','processing','shipped','delivered','cancelled','refunded') DEFAULT 'pending'");
        });
    }
};
