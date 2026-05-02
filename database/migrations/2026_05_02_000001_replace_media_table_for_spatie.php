<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the old custom `media` table so Spatie's published migration can
 * create the proper one without a "table already exists" error.
 *
 * The Spatie migration (2026_05_02_143849_create_media_table.php) runs
 * after this one because its timestamp is later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('media');
    }

    public function down(): void
    {
        // Nothing to restore — the old schema was replaced intentionally.
    }
};
