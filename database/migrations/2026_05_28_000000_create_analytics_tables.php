<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Visitors Table
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_uuid', 36)->unique();
            $table->string('ip_hash', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('browser', 100)->nullable();
            $table->string('device', 50)->nullable(); // mobile, tablet, desktop
            $table->string('operating_system', 100)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('country', 100)->nullable()->index();
            $table->string('city', 100)->nullable()->index();
            $table->string('region', 100)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();
        });

        // 2. Visitor Sessions Table
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_uuid', 36)->unique();
            $table->string('visitor_uuid', 36)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('referrer')->nullable();
            $table->string('utm_source', 100)->nullable()->index();
            $table->string('utm_medium', 100)->nullable()->index();
            $table->string('utm_campaign', 100)->nullable()->index();
            $table->string('utm_term', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->text('landing_page')->nullable();
            $table->timestamps();

            $table->foreign('visitor_uuid')->references('visitor_uuid')->on('visitors')->onDelete('cascade');
        });

        // 3. Page Views Table
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('session_uuid', 36)->index();
            $table->string('visitor_uuid', 36)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('url');
            $table->text('referrer')->nullable();
            $table->timestamp('visited_at')->index();
            $table->timestamps();

            $table->foreign('session_uuid')->references('session_uuid')->on('visitor_sessions')->onDelete('cascade');
            $table->foreign('visitor_uuid')->references('visitor_uuid')->on('visitors')->onDelete('cascade');
        });

        // 4. Analytics Events Table
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_uuid', 36)->index();
            $table->string('visitor_uuid', 36)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_name', 100)->index();
            $table->json('event_metadata')->nullable();
            $table->timestamp('visited_at')->index();
            $table->timestamps();

            $table->foreign('session_uuid')->references('session_uuid')->on('visitor_sessions')->onDelete('cascade');
            $table->foreign('visitor_uuid')->references('visitor_uuid')->on('visitors')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('page_views');
        Schema::dropIfExists('visitor_sessions');
        Schema::dropIfExists('visitors');
    }
};
