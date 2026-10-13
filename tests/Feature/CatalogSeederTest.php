<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $imagesDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake([PerformConversionsJob::class]);

        $this->imagesDir = storage_path('framework/testing/catalog-images-'.uniqid());
        File::ensureDirectoryExists($this->imagesDir.'/products');
        File::ensureDirectoryExists($this->imagesDir.'/categories');
        putenv('CATALOG_IMAGES_PATH='.$this->imagesDir);
    }

    protected function tearDown(): void
    {
        putenv('CATALOG_IMAGES_PATH');
        File::deleteDirectory($this->imagesDir);

        parent::tearDown();
    }

    private function png(string $path): void
    {
        File::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    public function test_seeds_women_modest_catalogue_and_keeps_source_images(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->png("{$this->imagesDir}/products/noor-classic-closed-abaya-{$n}.png");
        }
        $this->png("{$this->imagesDir}/categories/abayas.png");

        $this->seed(CatalogSeeder::class);

        $this->assertSame(6, Category::whereNull('parent_id')->count());
        $this->assertSame(24, Product::count());
        $this->assertDatabaseMissing('categories', ['slug' => 'demo-women']);

        $abaya = Product::where('slug', 'noor-classic-closed-abaya')->firstOrFail();
        $this->assertSame('published', $abaya->status);
        $this->assertCount(3, $abaya->getMedia('product_images'));
        $this->assertSame(15, $abaya->variants()->count());
        $this->assertEquals($abaya->variants()->sum('stock_qty'), $abaya->stock_qty);
        $this->assertFileExists("{$this->imagesDir}/products/noor-classic-closed-abaya-1.png");
        $this->assertTrue(Category::where('slug', 'abayas')->firstOrFail()->hasMedia('category_image'));

        // No images means it must not be shown in the storefront.
        $this->assertSame('draft', Product::where('slug', 'velvet-prayer-mat')->value('status'));
        // A product without options is a simple product.
        $this->assertSame(0, ProductVariant::whereHas('product', fn ($q) => $q->where('slug', 'magnetic-hijab-pins-set'))->count());
        $this->assertTrue(Product::where('slug', 'magnetic-hijab-pins-set')->value('in_stock'));
    }

    public function test_rerunning_does_not_duplicate_records_or_media(): void
    {
        $this->png("{$this->imagesDir}/products/noor-classic-closed-abaya-1.png");

        $this->seed(CatalogSeeder::class);
        $counts = [Category::count(), Product::count(), ProductVariant::count(), \DB::table('media')->count()];
        $this->seed(CatalogSeeder::class);

        $this->assertSame($counts, [Category::count(), Product::count(), ProductVariant::count(), \DB::table('media')->count()]);
    }

    public function test_catalogue_is_not_flagged_as_demo_content(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame(0, Product::where('sku', 'like', 'DEMO-%')->orWhere('slug', 'like', 'demo-%')->count());
        $this->assertSame(0, Category::where('slug', 'like', 'demo-%')->count());
    }
}
