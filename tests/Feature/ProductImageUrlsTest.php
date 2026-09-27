<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\WishlistItem;
use Database\Seeders\Concerns\CreatesDemoMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageUrlsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $category = Category::create(['name' => 'Images', 'slug' => 'images', 'is_active' => true]);
        $this->product = Product::factory()->create(['category_id' => $category->id]);
    }

    public function test_demo_seeder_media_has_generated_conversions(): void
    {
        $seeder = new class
        {
            use CreatesDemoMedia;

            public function attach(Product $product): void
            {
                $this->attachDemoImages($product, 'product_images', 2, 'Seeded');
            }
        };
        $seeder->attach($this->product);

        $media = $this->product->fresh()->getMedia('product_images');
        $this->assertCount(2, $media);
        foreach ($media as $item) {
            foreach (['product_thumb', 'product_card', 'product_detail', 'product_zoom'] as $conversion) {
                $this->assertTrue($item->hasGeneratedConversion($conversion), "{$conversion} missing");
                $this->assertFileExists($item->getPath($conversion));
            }
            $this->assertSame([$item->file_name, 'conversions'], $this->directoryEntries($item->id));
        }
    }

    public function test_public_image_urls_fall_back_to_original_until_conversions_exist(): void
    {
        $media = $this->product->addMedia(UploadedFile::fake()->image('cover.jpg', 50, 50))
            ->toMediaCollection('product_images');
        $media->generated_conversions = [];
        $media->save();

        $card = $this->getJson('/api/v1/products')->assertOk()->json('data.0.image');
        $this->assertSame($media->getUrl(), $card['card']);
        $this->assertSame($media->getUrl(), $card['thumb']);

        $detail = $this->getJson("/api/v1/products/{$this->product->slug}")->assertOk()->json('data');
        $this->assertSame($media->getUrl(), $detail['image']['zoom']);
        $this->assertSame($media->getUrl(), $detail['gallery'][0]['detail']);
    }

    public function test_admin_wishlist_images_come_from_the_product_gallery(): void
    {
        $media = $this->product->addMedia(UploadedFile::fake()->image('cover.jpg', 50, 50))
            ->toMediaCollection('product_images');

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']));
        $customer = User::factory()->create();
        WishlistItem::create(['user_id' => $customer->id, 'product_id' => $this->product->id]);

        $expected = $media->getUrl('product_card');
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/v1/admin/wishlist-analytics')->assertOk()
            ->assertJsonPath('data.data.0.image', $expected);
        $this->getJson('/api/v1/admin/wishlist-analytics/summary')->assertOk()
            ->assertJsonPath('data.top_product.image', $expected);
        $this->getJson("/api/v1/admin/users/{$customer->id}/wishlist")->assertOk()
            ->assertJsonPath('data.wishlist.0.image', $expected);
    }

    private function directoryEntries(int $mediaId): array
    {
        $disk = Storage::disk('public');
        $entries = array_merge($disk->files((string) $mediaId), $disk->directories((string) $mediaId));

        return array_map('basename', $entries);
    }
}
