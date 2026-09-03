<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_reserved_at')->nullable()->after('refunded_at');
            $table->timestamp('stock_released_at')->nullable()->after('stock_reserved_at');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_reserved_at', 'stock_released_at', 'refunded_amount']);
        });
    }
};
