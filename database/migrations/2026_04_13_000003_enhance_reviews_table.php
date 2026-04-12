<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // Track whether the review comes from a verified buyer
            $table->boolean('is_verified_purchase')->default(false)->after('is_approved');
            // Admin moderation note (reject reason, etc.)
            $table->string('admin_note')->nullable()->after('is_verified_purchase');
            // Track IP for anti-spam (optional, for future use)
            $table->ipAddress('ip_address')->nullable()->after('admin_note');

            // Performance indexes
            $table->index(['product_id', 'is_approved']);
            $table->index(['user_id', 'product_id']);
        });

        // One review per user per product (prevent duplicate reviews)
        // Using a separate call to avoid index name conflicts
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id'], 'reviews_user_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_product_unique');
            $table->dropIndex(['product_id', 'is_approved']);
            $table->dropIndex(['user_id', 'product_id']);
            $table->dropColumn(['is_verified_purchase', 'admin_note', 'ip_address']);
        });
    }
};
