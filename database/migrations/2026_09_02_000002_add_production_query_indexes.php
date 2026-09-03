<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'category_id'], 'products_status_category_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'created_at'], 'orders_user_status_created_index');
            $table->index(['status', 'created_at'], 'orders_status_created_index');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique('sku', 'product_variants_sku_unique');
        });

    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->index('category_id', 'products_category_id_restore_index'));
        Schema::table('orders', fn (Blueprint $table) => $table->index('user_id', 'orders_user_id_restore_index'));

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_status_created_index');
            $table->dropIndex('orders_status_created_index');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_status_category_index'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropUnique('product_variants_sku_unique'));
    }
};
