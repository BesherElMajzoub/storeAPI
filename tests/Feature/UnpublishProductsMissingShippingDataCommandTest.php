<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnpublishProductsMissingShippingDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_change_anything(): void
    {
        $missing = Product::factory()->create(['status' => 'published', 'weight_oz' => null]);
        $complete = Product::factory()->create(['status' => 'published']);

        $this->artisan('products:unpublish-missing-shipping-data')->assertSuccessful();

        $this->assertSame('published', $missing->fresh()->status);
        $this->assertSame('published', $complete->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_apply_moves_only_incomplete_published_products_to_draft(): void
    {
        $missingWeight = Product::factory()->create(['status' => 'published', 'weight_oz' => null]);
        $zeroLength = Product::factory()->create(['status' => 'published', 'length_in' => 0]);
        $complete = Product::factory()->create(['status' => 'published']);
        $alreadyDraft = Product::factory()->create(['status' => 'draft', 'weight_oz' => null]);

        $this->artisan('products:unpublish-missing-shipping-data', ['--apply' => true])->assertSuccessful();

        $this->assertSame('draft', $missingWeight->fresh()->status);
        $this->assertSame('draft', $zeroLength->fresh()->status);
        $this->assertSame('published', $complete->fresh()->status);
        $this->assertSame('draft', $alreadyDraft->fresh()->status);

        $audit = AuditLog::where('action', 'unpublished_products_missing_shipping_data')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$missingWeight->id, $zeroLength->id],
            $audit->changes['product_ids']
        );
    }

    public function test_reports_success_with_nothing_to_do(): void
    {
        Product::factory()->create(['status' => 'published']);

        $this->artisan('products:unpublish-missing-shipping-data', ['--apply' => true])
            ->expectsOutputToContain('No published products are missing shipping data.')
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::count());
    }
}
