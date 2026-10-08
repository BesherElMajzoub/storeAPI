<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'voided' = cancelled before any money was taken (unpaid order closed,
        // or card hold released), so reports keep 'failed' for real failures.
        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('unpaid', 'authorized', 'paid', 'failed', 'voided', 'refunded') NOT NULL DEFAULT 'unpaid'");

        Schema::table('orders', function (Blueprint $table) {
            // Set when a held payment cannot be captured: the order must not ship.
            $table->boolean('fulfillment_hold')->default(false)->after('fulfillment_started_at');
            $table->unsignedTinyInteger('capture_attempts')->default(0)->after('fulfillment_hold');
            $table->timestamp('capture_failed_at')->nullable()->after('capture_attempts');
            // Label PDF kept on the private disk so it outlives EasyPost's URL.
            $table->string('label_path')->nullable()->after('label_url');
        });

        DB::table('orders')
            ->where('status', 'cancelled')
            ->where('payment_status', 'failed')
            ->where(fn ($query) => $query->where('refund_status', 'released')->orWhereNull('authorized_at'))
            ->update(['payment_status' => 'voided']);
    }

    public function down(): void
    {
        DB::table('orders')->where('payment_status', 'voided')->update(['payment_status' => 'failed']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_hold', 'capture_attempts', 'capture_failed_at', 'label_path']);
        });

        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('unpaid', 'authorized', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid'");
    }
};
