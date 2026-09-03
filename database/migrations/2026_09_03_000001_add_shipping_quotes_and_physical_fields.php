<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_oz', 10, 2)->nullable()->after('stock_qty');
            $table->decimal('length_in', 10, 2)->nullable()->after('weight_oz');
            $table->decimal('width_in', 10, 2)->nullable()->after('length_in');
            $table->decimal('height_in', 10, 2)->nullable()->after('width_in');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('weight_oz', 10, 2)->nullable()->after('stock_qty');
            $table->decimal('length_in', 10, 2)->nullable()->after('weight_oz');
            $table->decimal('width_in', 10, 2)->nullable()->after('length_in');
            $table->decimal('height_in', 10, 2)->nullable()->after('width_in');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_rate_id')->nullable()->after('easypost_shipment_id');
            $table->string('shipping_carrier')->nullable()->after('shipping_rate_id');
            $table->string('shipping_service')->nullable()->after('shipping_carrier');
            $table->string('shipment_status')->nullable()->after('tracking_number');
            $table->text('tracking_url')->nullable()->after('shipment_status');
            $table->timestamp('shipped_at')->nullable()->after('tracking_url');
            $table->date('estimated_delivery')->nullable()->after('shipped_at');
            $table->json('tracking_events')->nullable()->after('estimated_delivery');
        });

        Schema::create('shipping_rate_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('rate_id')->unique();
            $table->string('shipment_id');
            $table->string('carrier');
            $table->string('service');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('eta_days')->nullable();
            $table->char('address_hash', 64);
            $table->char('items_hash', 64)->nullable();
            $table->char('parcel_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rate_quotes');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_rate_id', 'shipping_carrier', 'shipping_service',
                'shipment_status', 'tracking_url', 'shipped_at',
                'estimated_delivery', 'tracking_events',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['weight_oz', 'length_in', 'width_in', 'height_in']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight_oz', 'length_in', 'width_in', 'height_in']);
        });
    }
};
