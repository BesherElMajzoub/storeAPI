<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class SecureImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($admin, 'sanctum');
    }

    public function test_php_payload_renamed_to_jpg_without_an_image_is_rejected(): void
    {
        $product = Product::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($path, '<?php echo "owned";');
        $file = new UploadedFile($path, 'shell.php.jpg', 'image/jpeg', null, true);

        $this->post("/api/v1/admin/products/{$product->id}/images", ['images' => [$file]], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images.0');
    }

    public function test_jpeg_polyglot_gets_a_random_non_executable_name_and_safe_webp_conversions(): void
    {
        $product = Product::factory()->create();
        $file = UploadedFile::fake()->image('shell.php.jpg', 20, 20);
        file_put_contents($file->getPathname(), '<?php echo "owned";', FILE_APPEND);

        $this->post("/api/v1/admin/products/{$product->id}/images", ['images' => [$file]], ['Accept' => 'application/json'])
            ->assertCreated();

        $media = Media::query()->where('model_type', Product::class)->where('model_id', $product->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.(?:jpg|jpeg)$/', $media->file_name);
        $this->assertStringNotContainsString('.php', $media->file_name);

        $conversionPath = $media->getPath('product_card');
        $this->assertFileExists($conversionPath);
        $this->assertStringNotContainsString('<?php', file_get_contents($conversionPath));
        $this->assertSame('webp', pathinfo($conversionPath, PATHINFO_EXTENSION));
    }

    public function test_product_image_upload_returns_only_the_documented_uploaded_image_array(): void
    {
        $product = Product::factory()->create();
        $file = UploadedFile::fake()->image('contract.jpg', 20, 20);

        $response = $this->post(
            "/api/v1/admin/products/{$product->id}/images",
            ['images' => [$file]],
            ['Accept' => 'application/json']
        )->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [[
                    'id',
                    'file_name',
                    'mime_type',
                    'size',
                    'order',
                    'url',
                ]],
                'errors',
            ]);

        $this->assertSame(
            ['id', 'file_name', 'mime_type', 'size', 'order', 'url'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_product_gallery_is_limited_to_eight_images(): void
    {
        $product = Product::factory()->create();
        $images = [];
        for ($i = 0; $i < 9; $i++) {
            $images[] = UploadedFile::fake()->image("{$i}.jpg", 10, 10);
        }

        $this->post("/api/v1/admin/products/{$product->id}/images", ['images' => $images], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images');
    }

    public function test_canonical_image_order_endpoint_accepts_stable_image_ids(): void
    {
        $product = Product::factory()->create();
        $first = $product->addMedia(UploadedFile::fake()->image('first.jpg', 10, 10))->toMediaCollection('product_images');
        $second = $product->addMedia(UploadedFile::fake()->image('second.jpg', 10, 10))->toMediaCollection('product_images');

        $this->postJson("/api/v1/admin/products/{$product->id}/images/order", [
            'image_ids' => [$second->id, $first->id],
        ])->assertOk()->assertJsonPath('data', null);

        $this->assertSame(1, $second->fresh()->order_column);
        $this->assertSame(2, $first->fresh()->order_column);
    }

    public function test_product_image_delete_returns_empty_data_and_removes_only_the_target_image(): void
    {
        $product = Product::factory()->create();
        $deleted = $product->addMedia(UploadedFile::fake()->image('delete.jpg', 10, 10))->toMediaCollection('product_images');
        $kept = $product->addMedia(UploadedFile::fake()->image('keep.jpg', 10, 10))->toMediaCollection('product_images');

        $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$deleted->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('media', ['id' => $deleted->id]);
        $this->assertDatabaseHas('media', ['id' => $kept->id]);
    }
}
