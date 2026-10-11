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
            $table->string('shipment_source', 20)->nullable()->after('shipment_status');
            $table->string('refund_failure_code')->nullable()->after('refund_status');
            $table->text('refund_failure_message')->nullable()->after('refund_failure_code');
            $table->timestamp('refund_failed_at')->nullable()->after('refund_failure_message');
        });

        DB::table('orders')
            ->whereNotNull('tracking_number')
            ->where(function ($query): void {
                $query->whereNotNull('label_url')->orWhereNotNull('label_path');
            })
            ->update(['shipment_source' => 'easypost']);

        DB::table('orders')
            ->whereNotNull('tracking_number')
            ->whereNull('shipment_source')
            ->update(['shipment_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipment_source',
                'refund_failure_code',
                'refund_failure_message',
                'refund_failed_at',
            ]);
        });
    }
};
