<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('identifier');
            $table->string('purpose');
            $table->string('channel')->default('email');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->date('sent_count_date')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['identifier', 'purpose', 'channel']);
            $table->index(['identifier', 'purpose']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
