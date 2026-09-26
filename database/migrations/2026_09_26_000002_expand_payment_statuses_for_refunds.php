<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY status ENUM('pending', 'completed', 'failed', 'requires_refund', 'partially_refunded', 'refunded') NOT NULL");
    }

    public function down(): void
    {
        DB::table('payments')->whereIn('status', ['requires_refund', 'partially_refunded', 'refunded'])
            ->update(['status' => 'completed']);

        DB::statement("ALTER TABLE payments MODIFY status ENUM('pending', 'completed', 'failed') NOT NULL");
    }
};
