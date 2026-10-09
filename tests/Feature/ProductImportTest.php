<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

    public function test_update_without_category_slug_keeps_existing_category(): void
    {
        $category = Category::create(['name' => 'Dresses', 'slug' => 'dresses']);
        $product = Product::factory()->create(['sku' => 'P-1', 'category_id' => $category->id, 'status' => 'published']);

        $this->import("type,sku,name,price,stock_qty\nproduct,P-1,Renamed,10,5")
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.rows.0.action', 'update');

        $product->refresh();
        $this->assertSame('Renamed', $product->name);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('published', $product->status);
    }

    public function test_null_category_slug_clears_category_on_draft_product(): void
    {
        $category = Category::create(['name' => 'Dresses', 'slug' => 'dresses']);
        $product = Product::factory()->create(['sku' => 'P-2', 'category_id' => $category->id, 'status' => 'draft']);

        $this->import("type,sku,name,price,category_slug\nproduct,P-2,Draft,10,NULL")
            ->assertOk()
            ->assertJsonPath('data.committed', true);

        $this->assertNull($product->fresh()->category_id);
    }

    public function test_null_category_slug_on_published_product_is_rejected(): void
    {
        $category = Category::create(['name' => 'Dresses', 'slug' => 'dresses']);
        $product = Product::factory()->create(['sku' => 'P-3', 'category_id' => $category->id, 'status' => 'published']);

        $this->import("type,sku,name,price,category_slug\nproduct,P-3,Published,10,NULL")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.action', 'error')
            ->assertJsonPath('data.rows.0.errors.category_slug.0', 'Published products require a category.');

        $this->assertSame($category->id, $product->fresh()->category_id);
    }

    public function test_existing_variant_sku_cannot_move_to_another_product(): void
    {
        $productA = Product::factory()->create(['sku' => 'A', 'stock_qty' => 10]);
        Product::factory()->create(['sku' => 'B', 'stock_qty' => 10]);
        $variant = $productA->variants()->create(['sku' => 'V-1', 'name' => 'V', 'stock_qty' => 1]);

        $this->import("type,sku,name,parent_sku\nvariant,V-1,V,B")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.action', 'error')
            ->assertJsonPath('data.rows.0.errors.sku.0', 'SKU belongs to a variant of another product (A).');

        $this->assertSame($productA->id, $variant->fresh()->product_id);
    }

    public function test_null_dimension_on_published_product_is_rejected(): void
    {
        Category::create(['name' => 'X', 'slug' => 'x']);
        $product = Product::factory()->create(['sku' => 'D-1', 'status' => 'published', 'weight_oz' => 12]);

        $this->import("type,sku,name,price,category_slug,weight_oz\nproduct,D-1,D,10,x,NULL")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.weight_oz.0', 'Published products require complete shipping weight and dimensions.');

        $this->assertSame('12.00', $product->fresh()->weight_oz);
    }

    public function test_publishing_existing_product_uses_stored_dimensions_and_category(): void
    {
        $category = Category::create(['name' => 'Dresses', 'slug' => 'dresses']);
        $product = Product::factory()->create(['sku' => 'E-1', 'category_id' => $category->id, 'status' => 'draft']);

        $this->import("type,sku,name,price,status\nproduct,E-1,E,10,published")
            ->assertOk()
            ->assertJsonPath('data.rows.0.action', 'update');

        $product->refresh();
        $this->assertSame('published', $product->status);
        $this->assertSame($category->id, $product->category_id);
    }

    public function test_creating_published_product_without_dimensions_is_rejected(): void
    {
        Category::create(['name' => 'Dresses', 'slug' => 'dresses']);

        $this->import("type,sku,name,price,status,category_slug\nproduct,NEW-1,New,10,published,dresses")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.weight_oz.0', 'Published products require complete shipping weight and dimensions.')
            ->assertJsonPath('data.rows.0.errors.height_in.0', 'Published products require complete shipping weight and dimensions.');

        $this->assertDatabaseMissing('products', ['sku' => 'NEW-1']);
    }

    public function test_variant_stock_sum_exceeding_product_stock_is_rejected(): void
    {
        $message = "The total stock quantity of variants (50) cannot exceed the product's stock quantity (1).";

        $this->import(implode("\n", [
            'type,sku,name,parent_sku,price,stock_qty,status',
            'product,S-1,S,,10,1,draft',
            'variant,S-1-A,A,S-1,,50,',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('data.summary.errors', 2)
            ->assertJsonPath('data.rows.0.errors.stock_qty.0', $message)
            ->assertJsonPath('data.rows.1.errors.stock_qty.0', $message);

        $this->assertDatabaseMissing('products', ['sku' => 'S-1']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'S-1-A']);
    }

    public function test_variant_stock_check_includes_existing_variants_not_in_csv(): void
    {
        $product = Product::factory()->create(['sku' => 'S-2', 'stock_qty' => 10]);
        $product->variants()->create(['sku' => 'S-2-A', 'name' => 'A', 'stock_qty' => 8]);

        $this->import("type,sku,name,parent_sku,stock_qty\nvariant,S-2-B,B,S-2,5")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.stock_qty.0', "The total stock quantity of variants (13) cannot exceed the product's stock quantity (10).");

        $this->assertDatabaseMissing('product_variants', ['sku' => 'S-2-B']);
    }

    public function test_lowering_product_stock_below_existing_variant_sum_is_rejected(): void
    {
        $product = Product::factory()->create(['sku' => 'S-3', 'stock_qty' => 10]);
        $product->variants()->create(['sku' => 'S-3-A', 'name' => 'A', 'stock_qty' => 8]);

        $this->import("type,sku,name,price,stock_qty\nproduct,S-3,S,10,5")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.stock_qty.0', "The total stock quantity of variants (8) cannot exceed the product's stock quantity (5).");

        $this->assertSame(10, $product->fresh()->stock_qty);
    }

    public function test_lowering_price_below_existing_discount_is_rejected(): void
    {
        $product = Product::factory()->create(['sku' => 'B-1', 'price' => 100, 'discount_price' => 80]);

        $this->import("type,sku,name,price\nproduct,B-1,B,50")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.discount_price.0', 'The discount price must be less than price.');

        $this->assertSame('100.00', $product->fresh()->price);
    }

    public function test_update_row_without_price_keeps_existing_price(): void
    {
        $product = Product::factory()->create(['sku' => 'B-2', 'price' => 100, 'status' => 'draft']);

        $this->import("type,sku,name\nproduct,B-2,Renamed")
            ->assertOk()
            ->assertJsonPath('data.rows.0.action', 'update');

        $product->refresh();
        $this->assertSame('Renamed', $product->name);
        $this->assertSame('100.00', $product->price);
    }

    public function test_create_row_without_price_is_rejected(): void
    {
        $this->import("type,sku,name\nproduct,B-3,New")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.price.0', 'The price field is required.');

        $this->assertDatabaseMissing('products', ['sku' => 'B-3']);
    }

    public function test_invalid_type_reports_only_type_error(): void
    {
        $this->import("type,sku,name,parent_sku,price\nprodcut,T-1,T,,10", true)
            ->assertUnprocessable()
            ->assertJsonPath('data.summary.errors', 1)
            ->assertJsonPath('data.rows.0.action', 'error')
            ->assertJsonPath('data.rows.0.errors', ['type' => ['Type must be product or variant.']]);
    }

    public function test_variant_parent_sku_matches_csv_product_case_insensitively(): void
    {
        $this->import(implode("\n", [
            'type,sku,name,parent_sku,price,stock_qty',
            'Product,ABC-1,Bag,,10,5',
            'variant,ABC-1-RED,Red,abc-1,,2',
        ]))->assertOk()->assertJsonPath('data.committed', true);

        $product = Product::where('sku', 'ABC-1')->firstOrFail();
        $this->assertDatabaseHas('product_variants', ['sku' => 'ABC-1-RED', 'product_id' => $product->id]);
    }

    public function test_unique_conflict_during_commit_returns_409_without_writes(): void
    {
        Product::creating(function (Product $product) {
            if ($product->sku === 'RACE-2') {
                DB::table('products')->insert([
                    'name' => 'Concurrent', 'slug' => 'concurrent-race', 'sku' => 'RACE-2', 'price' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->import("type,sku,name,price\nproduct,RACE-1,First,10\nproduct,RACE-2,Second,10")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The catalog changed during import; run the preview again.');

        $this->assertDatabaseMissing('products', ['sku' => 'RACE-1']);
        $this->assertDatabaseMissing('products', ['name' => 'Second']);
    }

    public function test_malformed_json_reports_valid_json_message(): void
    {
        $this->import(implode("\n", [
            'type,sku,name,price,options',
            'product,J-1,One,10,"{bad"',
            'product,J-2,Two,10,5',
        ]), true)
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.options', ['options must be valid JSON.'])
            ->assertJsonPath('data.rows.1.errors.options', ['The options field must be an array.']);
    }

    public function test_null_in_non_nullable_column_is_a_row_error(): void
    {
        $product = Product::factory()->create(['sku' => 'N-1', 'stock_qty' => 7]);

        $this->import("type,sku,name,price,stock_qty\nproduct,N-1,N,10,NULL")
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.stock_qty.0', 'The stock qty field must be an integer.');

        $this->assertSame(7, $product->fresh()->stock_qty);
    }

    public function test_database_errors_are_not_reported_as_csv_file_errors(): void
    {
        $this->mock(ProductImportService::class, fn ($mock) => $mock->shouldReceive('process')->andThrow(
            new QueryException('mysql', 'insert into `products`', [], new \PDOException('SQLSTATE[HY000]: General error'))
        ));

        $this->import("type,sku,name,price\nproduct,Q-1,Q,10")
            ->assertStatus(500)
            ->assertJsonMissingPath('errors.file');
    }

    public function test_rows_without_sku_and_numeric_skus_are_handled(): void
    {
        $this->import(implode("\n", [
            'type,sku,name,parent_sku,price,stock_qty',
            'product,,No Sku,,10,1',
            'variant,,No Sku Variant,,,',
            'product,12345,Numeric,,10,5',
            'variant,12345-1,Numeric Variant,12345,,2',
        ]), true)
            ->assertUnprocessable()
            ->assertJsonPath('data.rows.0.errors.sku.0', 'The sku field is required.')
            ->assertJsonPath('data.rows.1.errors.sku.0', 'The sku field is required.')
            ->assertJsonPath('data.rows.2.action', 'create')
            ->assertJsonPath('data.rows.3.action', 'create');
    }

    public function test_five_thousand_row_import_uses_bounded_queries(): void
    {
        Category::create(['name' => 'Bulk', 'slug' => 'bulk']);
        $rows = ['type,sku,name,parent_sku,price,stock_qty,status,category_slug'];
        for ($index = 1; $index <= 4000; $index++) {
            $rows[] = "product,BULK-{$index},Bulk {$index},,10,5,draft,bulk";
        }
        for ($index = 1; $index <= 1000; $index++) {
            $rows[] = "variant,BULK-{$index}-V,Variant {$index},BULK-{$index},,2,,";
        }
        $csv = implode("\n", $rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        $response = $this->import($csv, true);
        $elapsed = microtime(true) - $started;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        if (getenv('IMPORT_PERF')) {
            fwrite(STDERR, sprintf("\n[import-perf] queries=%d time=%.2fs\n", $queries, $elapsed));
        }

        $response->assertOk()
            ->assertJsonPath('data.summary.creates', 5000)
            ->assertJsonPath('data.summary.errors', 0);
        $this->assertLessThan(50, $queries, "Import preview ran {$queries} queries.");
    }

    public function test_headers_are_case_insensitive(): void
    {
        $this->import(" Type ,SKU,Name,PRICE\nproduct,H-1,Header Case,10")
            ->assertOk()
            ->assertJsonPath('data.rows.0.action', 'create')
            ->assertJsonPath('data.warnings', []);

        $this->assertDatabaseHas('products', ['sku' => 'H-1', 'name' => 'Header Case', 'price' => 10]);
    }

    public function test_semicolon_delimiter_is_detected(): void
    {
        $this->import("type;sku;name;price;description\nproduct;SC-1;Semi;10;\"a, b\"")
            ->assertOk()
            ->assertJsonPath('data.committed', true);

        $this->assertDatabaseHas('products', ['sku' => 'SC-1', 'name' => 'Semi', 'description' => 'a, b']);
    }

    public function test_non_utf8_file_is_rejected(): void
    {
        $this->import("type,sku,name,price\nproduct,L-1,Caf\xE9,10")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'CSV must be UTF-8 encoded. In Excel use "CSV UTF-8 (Comma delimited)".');

        $this->assertDatabaseMissing('products', ['sku' => 'L-1']);
    }

    public function test_unknown_columns_are_reported_as_warnings(): void
    {
        $this->import("type,sku,name,price,stok_qty\nproduct,W-1,Warned,10,5", true)
            ->assertOk()
            ->assertJsonPath('data.summary.errors', 0)
            ->assertJsonPath('data.warnings', ['Unknown column "stok_qty" is ignored.']);
    }

    public function test_preview_reports_changes_for_update_rows(): void
    {
        $product = Product::factory()->create([
            'sku' => 'CH-1', 'name' => 'Old', 'price' => 100, 'stock_qty' => 5, 'in_stock' => true,
            'options' => ['Color'], 'is_featured' => false, 'status' => 'draft',
        ]);
        $product->variants()->create(['sku' => 'CH-1-A', 'name' => 'A', 'price' => 20, 'stock_qty' => 2]);

        $this->import(implode("\n", [
            'type,sku,name,parent_sku,price,stock_qty,options,is_featured',
            'product,CH-1,New Name,,100.00,5,"[""Color""]",true',
            'product,CH-2,Created,,10,1,,',
            'variant,CH-1-A,A,CH-1,20.5,3,,',
            'variant,CH-1-A-SAME,Same,CH-2,,,,',
        ]), true)
            ->assertOk()
            ->assertJsonPath('data.rows.0.action', 'update')
            ->assertJsonPath('data.rows.0.changes', ['name' => ['Old', 'New Name'], 'is_featured' => [false, true]])
            ->assertJsonPath('data.rows.1.action', 'create')
            ->assertJsonPath('data.rows.1.changes', null)
            ->assertJsonPath('data.rows.2.action', 'update')
            ->assertJsonPath('data.rows.2.changes', ['price' => ['20.00', '20.50'], 'stock_qty' => [2, 3]])
            ->assertJsonPath('data.rows.3.action', 'create')
            ->assertJsonPath('data.rows.3.changes', null);

        $this->import("type,sku,name,price\nproduct,CH-1,Old,100", true)
            ->assertOk()
            ->assertJsonPath('data.rows.0.action', 'update')
            ->assertJsonPath('data.rows.0.changes', []);
    }

    public function test_successful_commit_is_audit_logged(): void
    {
        $csv = "type,sku,name,price\nproduct,AU-1,Audited,10";

        $this->import($csv, true)->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'import_products']);

        $this->import($csv)->assertOk();
        $log = AuditLog::where('action', 'import_products')->sole();
        $this->assertSame('Imported products from CSV', $log->description);
        $this->assertSame(['AU-1'], $log->changes['skus']);
        $this->assertSame(1, $log->changes['summary']['creates']);
    }

    public function test_row_numbers_match_spreadsheet_rows_with_blank_lines_and_multiline_cells(): void
    {
        $this->import(implode("\n", [
            'type,sku,name,price,description',
            'product,R-1,One,10,"line a',
            'line b"',
            'product,R-2,Two,10,',
            '',
            ',,,,',
            'product,R-3,,10,',
        ]), true)
            ->assertUnprocessable()
            ->assertJsonPath('data.summary.rows', 3)
            ->assertJsonPath('data.rows.0.row', 2)
            ->assertJsonPath('data.rows.1.row', 3)
            ->assertJsonPath('data.rows.2.row', 6)
            ->assertJsonPath('data.rows.2.errors.name.0', 'The name field is required.');
    }

    private function import(string $contents, bool $dryRun = false): TestResponse
    {
        return $this->post('/api/v1/admin/products/import', [
            'file' => $this->csv($contents),
            'dry_run' => $dryRun ? 'true' : 'false',
        ], ['Accept' => 'application/json']);
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $contents)->mimeType('text/csv');
    }
}
