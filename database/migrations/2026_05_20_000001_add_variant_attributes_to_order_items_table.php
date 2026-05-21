<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot of the variant's attributes (e.g. {"color":"Gold","size":"18in"})
            // stored at order-creation time so the data is preserved even if the
            // variant is later edited or deleted.
            $table->json('variant_attributes')->nullable()->after('variant_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('variant_attributes');
        });
    }
};
