<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update coupons table
        Schema::table('coupons', function (Blueprint $table) {
            if (!Schema::hasColumn('coupons', 'minimum_order_amount')) {
                $table->decimal('minimum_order_amount', 12, 2)->nullable()->after('value');
            }
            if (!Schema::hasColumn('coupons', 'maximum_discount_amount')) {
                $table->decimal('maximum_discount_amount', 12, 2)->nullable()->after('minimum_order_amount');
            }
            if (!Schema::hasColumn('coupons', 'usage_limit_per_user')) {
                $table->integer('usage_limit_per_user')->nullable()->after('used_count');
            }
        });

        // 2. Create coupon_usages table
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamps();
        });

        // 3. Update orders table
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('user_id')->constrained('coupons')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn('coupon_id');
        });

        Schema::dropIfExists('coupon_usages');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn([
                'minimum_order_amount',
                'maximum_discount_amount',
                'usage_limit_per_user'
            ]);
        });
    }
};
