<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Placeholder migration — keeps the product_images table intact during
 * the transition to Spatie MediaLibrary.
 *
 * The table and its rows will be consumed by the
 * `php artisan media:migrate-legacy` command, which copies every
 * ProductImage file into Spatie's media collection.
 *
 * Once you have confirmed all images are migrated you can create a
 * follow-up migration to drop product_images and categories.image.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: product_images table stays until legacy data is migrated.
    }

    public function down(): void
    {
        // No-op.
    }
};
