<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'easypost_shipment_id')) {
                $table->string('easypost_shipment_id')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('orders', 'tracking_number')) {
                $table->string('tracking_number')->nullable()->after('easypost_shipment_id');
            }
            if (!Schema::hasColumn('orders', 'label_url')) {
                $table->text('label_url')->nullable()->after('tracking_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'easypost_shipment_id',
                'tracking_number',
                'label_url',
            ]);
        });
    }
};
