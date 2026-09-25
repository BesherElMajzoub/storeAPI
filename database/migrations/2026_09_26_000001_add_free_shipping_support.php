<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'carrier_shipping_cost')) {
                // The real EasyPost quote amount, preserved for accounting even
                // when the customer-facing shipping_cost is waived to 0.
                $table->decimal('carrier_shipping_cost', 12, 2)->nullable()->after('shipping_cost');
            }
            if (! Schema::hasColumn('orders', 'free_shipping_reason')) {
                $table->string('free_shipping_reason')->nullable()->after('carrier_shipping_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['carrier_shipping_cost', 'free_shipping_reason']);
        });
    }
};
