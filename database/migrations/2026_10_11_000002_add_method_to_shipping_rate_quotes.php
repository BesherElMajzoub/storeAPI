<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rate_quotes', function (Blueprint $table): void {
            $table->string('method', 20)->nullable()->after('service')->index();
        });

        DB::table('shipping_rate_quotes')
            ->whereNull('method')
            ->update([
                'method' => DB::raw("CASE WHEN UPPER(carrier) = 'USPS' THEN 'standard' ELSE 'express' END"),
            ]);
    }

    public function down(): void
    {
        Schema::table('shipping_rate_quotes', function (Blueprint $table): void {
            $table->dropIndex(['method']);
            $table->dropColumn('method');
        });
    }
};
