<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time command to migrate legacy images into Spatie MediaLibrary.
 *
 * Usage:
 *   php artisan media:migrate-legacy
 *   php artisan media:migrate-legacy --dry-run   (preview without writing)
 *   php artisan media:migrate-legacy --only=products
 *   php artisan media:migrate-legacy --only=categories
 */
class MigrateImagesToSpatie extends Command
{
    protected $signature = 'media:migrate-legacy
                            {--dry-run : Preview actions without actually migrating}
                            {--only=all : Scope to "products", "categories", or "all"}';

    protected $description = 'Migrate legacy product_images and categories.image data into Spatie MediaLibrary.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only   = $this->option('only');

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be moved, no DB records created.');
        }

        if (in_array($only, ['products', 'all'])) {
            $this->migrateProducts($dryRun);
        }

        if (in_array($only, ['categories', 'all'])) {
            $this->migrateCategories($dryRun);
        }

        $this->info('');
        $this->info('✅ Migration complete!');

        if (! $dryRun) {
            $this->warn('Remember to run queue worker to process image conversions:');
            $this->warn('  php artisan queue:work --queue=default');
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────
    //  Products
    // ──────────────────────────────────────────

    private function migrateProducts(bool $dryRun): void
    {
        $this->info('');
        $this->info('=== Migrating product images ===');

        $images = ProductImage::with('product')->get();

        if ($images->isEmpty()) {
            $this->line('  No ProductImage records found.');
            return;
        }

        $bar = $this->output->createProgressBar($images->count());
        $bar->start();

        $ok = 0;
        $fail = 0;

        foreach ($images as $img) {
            $bar->advance();

            if (! $img->product) {
                $this->newLine();
                $this->warn("  Skipped ProductImage #{$img->id} — product missing.");
                $fail++;
                continue;
            }

            // Check the file actually exists on disk
            $diskPath = Storage::disk('public')->path($img->path ?? '');
            if (! $img->path || ! file_exists($diskPath)) {
                $this->newLine();
                $this->warn("  Skipped ProductImage #{$img->id} — file not found: {$img->path}");
                $fail++;
                continue;
            }

            // Skip if already migrated (idempotent)
            $alreadyMigrated = $img->product
                ->getMedia('product_images')
                ->contains(fn ($m) => $m->getCustomProperty('legacy_id') == $img->id);

            if ($alreadyMigrated) {
                $ok++;
                continue;
            }

            if (! $dryRun) {
                try {
                    $img->product
                        ->addMedia($diskPath)
                        ->preservingOriginal()               // keep file in place
                        ->withCustomProperties(['legacy_id' => $img->id])
                        ->usingFileName($img->original_name ?? basename($diskPath))
                        ->toMediaCollection('product_images');

                    $ok++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("  Failed ProductImage #{$img->id}: {$e->getMessage()}");
                    $fail++;
                }
            } else {
                $this->newLine();
                $this->line("  [DRY] Would migrate ProductImage #{$img->id} → product #{$img->product_id} ({$img->path})");
                $ok++;
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("  Products done — {$ok} migrated, {$fail} failed/skipped.");
    }

    // ──────────────────────────────────────────
    //  Categories
    // ──────────────────────────────────────────

    private function migrateCategories(bool $dryRun): void
    {
        $this->info('');
        $this->info('=== Migrating category images ===');

        // Use the raw column value to avoid the (now-removed) accessor
        $categories = Category::withTrashed()
            ->whereNotNull('image')
            ->get();

        if ($categories->isEmpty()) {
            $this->line('  No categories with image column found.');
            return;
        }

        $bar = $this->output->createProgressBar($categories->count());
        $bar->start();

        $ok = 0;
        $fail = 0;

        foreach ($categories as $cat) {
            $bar->advance();

            $rawImage = $cat->getRawOriginal('image');

            // Skip if already migrated
            if ($cat->getFirstMedia('category_image')) {
                $ok++;
                continue;
            }

            if (str_starts_with($rawImage, 'http')) {
                // External URL — add from URL
                if (! $dryRun) {
                    try {
                        $cat->addMediaFromUrl($rawImage)->toMediaCollection('category_image');
                        $ok++;
                    } catch (\Throwable $e) {
                        $this->newLine();
                        $this->error("  Failed category #{$cat->id}: {$e->getMessage()}");
                        $fail++;
                    }
                } else {
                    $this->newLine();
                    $this->line("  [DRY] Would add from URL for category #{$cat->id}: {$rawImage}");
                    $ok++;
                }
                continue;
            }

            $diskPath = Storage::disk('public')->path($rawImage);
            if (! file_exists($diskPath)) {
                $this->newLine();
                $this->warn("  Skipped category #{$cat->id} — file not found: {$rawImage}");
                $fail++;
                continue;
            }

            if (! $dryRun) {
                try {
                    $cat->addMedia($diskPath)
                        ->preservingOriginal()
                        ->withCustomProperties(['legacy_column' => 'categories.image'])
                        ->toMediaCollection('category_image');

                    $ok++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("  Failed category #{$cat->id}: {$e->getMessage()}");
                    $fail++;
                }
            } else {
                $this->newLine();
                $this->line("  [DRY] Would migrate category #{$cat->id}: {$rawImage}");
                $ok++;
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("  Categories done — {$ok} migrated, {$fail} failed/skipped.");

        if (! $dryRun && $ok > 0) {
            if ($this->confirm('Null out categories.image column for migrated categories?', false)) {
                Category::withTrashed()
                    ->whereNotNull('image')
                    ->whereHas('media', fn ($q) => $q->where('collection_name', 'category_image'))
                    ->update(['image' => null]);

                $this->info('  categories.image column cleared for migrated rows.');
            }
        }
    }
}
