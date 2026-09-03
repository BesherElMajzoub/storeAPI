<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($admin, 'sanctum');
    }

    public function test_preview_and_commit_share_the_same_product_and_variant_analysis(): void
    {
        Category::create(['name' => 'Dresses', 'slug' => 'dresses']);
        $csv = implode("\n", [
            'type,sku,name,parent_sku,price,stock_qty,status,category_slug,weight_oz,length_in,width_in,height_in',
            'product,DRESS-1,Evening Dress,,120,8,published,dresses,12,10,8,3',
            'variant,DRESS-1-BLK,Black / M,DRESS-1,125,3,,,,,,,',
        ]);

        $preview = $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv($csv),
            'dry_run' => 'true',
        ], ['Accept' => 'application/json']);

        $preview->assertOk()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.summary.creates', 2)
            ->assertJsonPath('data.summary.errors', 0);
        $this->assertDatabaseMissing('products', ['sku' => 'DRESS-1']);

        $commit = $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv($csv),
            'dry_run' => 'false',
        ], ['Accept' => 'application/json']);

        $commit->assertOk()->assertJsonPath('data.committed', true);
        $product = Product::where('sku', 'DRESS-1')->firstOrFail();
        $this->assertSame('published', $product->status);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'DRESS-1-BLK',
            'name' => 'Black / M',
        ]);
    }

    public function test_any_invalid_row_prevents_all_writes_and_reports_row_number(): void
    {
        $csv = implode("\n", [
            'type,sku,name,parent_sku,price,status',
            'product,VALID-1,Valid Product,,25,draft',
            'variant,ORPHAN-1,Orphan,DOES-NOT-EXIST,10,',
        ]);

        $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv($csv),
            'dry_run' => 'false',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.summary.errors', 1)
            ->assertJsonPath('data.rows.1.row', 3)
            ->assertJsonPath('data.rows.1.action', 'error');

        $this->assertDatabaseMissing('products', ['sku' => 'VALID-1']);
    }

    public function test_duplicate_sku_and_malformed_json_are_reported(): void
    {
        $csv = implode("\n", [
            'type,sku,name,parent_sku,price,status,options',
            'product,DUP-1,First,,10,draft,"not-json"',
            'product,DUP-1,Second,,12,draft,',
        ]);

        $response = $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv($csv),
            'dry_run' => 'true',
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonPath('data.summary.errors', 2);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_files_over_five_thousand_rows_are_rejected_without_writes(): void
    {
        $rows = ['type,sku,name,parent_sku,price,status'];
        for ($index = 1; $index <= 5001; $index++) {
            $rows[] = "product,LIMIT-{$index},Product {$index},,10,draft";
        }

        $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv(implode("\n", $rows)),
            'dry_run' => 'false',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'CSV files may contain at most 5,000 data rows.');

        $this->assertDatabaseCount('products', 0);
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $contents)->mimeType('text/csv');
    }
}
