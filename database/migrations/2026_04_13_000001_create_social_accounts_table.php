<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                   // google, apple, facebook, etc.
            $table->string('provider_id');                // Google's "sub" claim (unique user ID)
            $table->string('provider_email')->nullable(); // email from provider
            $table->string('avatar_url')->nullable();     // profile picture URL
            $table->json('provider_data')->nullable();    // raw token payload for debugging
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->index(['provider', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
