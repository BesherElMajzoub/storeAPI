<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // zelle | paypal: how the manual part of a refund was sent, calculated from the attempts.
            $table->string('refunded_method', 16)->nullable()->after('refunded_via');
        });

        // Manual refunds recorded before this column existed.
        $latest = DB::table('refund_attempts')->where('channel', 'manual')->where('status', 'recorded')
            ->orderBy('attempt_no')->get(['order_id', 'method']);
        foreach ($latest as $attempt) {
            DB::table('orders')->where('id', $attempt->order_id)->update(['refunded_method' => $attempt->method]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_method');
        });
    }
};
