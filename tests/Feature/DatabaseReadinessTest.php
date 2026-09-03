<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseReadinessTest extends TestCase
{
    use DatabaseTruncation;

    public function test_required_commerce_indexes_exist(): void
    {
        $this->assertIndex('products', ['slug'], true);
        $this->assertIndex('products', ['category_id']);
        $this->assertIndex('products', ['status', 'category_id']);
        $this->assertIndex('orders', ['user_id', 'status', 'created_at']);
        $this->assertIndex('orders', ['status', 'created_at']);
        $this->assertIndex('order_items', ['order_id']);
        $this->assertIndex('order_items', ['product_id']);
        $this->assertIndex('coupons', ['code'], true);
        $this->assertIndex('reviews', ['product_id']);
        $this->assertIndex('reviews', ['user_id', 'product_id']);
        $this->assertIndex('users', ['email'], true);
    }

    public function test_explain_runs_for_store_listing_search_and_admin_order_queries(): void
    {
        $listing = DB::select(
            'EXPLAIN SELECT * FROM products WHERE status = ? AND category_id = ? ORDER BY created_at DESC LIMIT 20',
            ['published', 1]
        );
        $search = DB::select(
            'EXPLAIN SELECT * FROM products WHERE status = ? AND (name LIKE ? OR sku LIKE ? OR slug LIKE ?) ORDER BY created_at DESC LIMIT 20',
            ['published', '%dress%', '%dress%', '%dress%']
        );
        $orders = DB::select(
            'EXPLAIN SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC LIMIT 20',
            ['processing']
        );

        $this->assertNotEmpty($listing);
        $this->assertNotEmpty($search);
        $this->assertNotEmpty($orders);
        $this->assertStringContainsString('products_status_category_index', (string) $listing[0]->possible_keys);
        $this->assertStringContainsString('products_status_category_index', (string) $search[0]->possible_keys);
        $this->assertStringContainsString('orders_status_created_index', (string) $orders[0]->possible_keys);
    }

    private function assertIndex(string $table, array $columns, bool $unique = false): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->groupBy('Key_name')
            ->map(fn ($rows) => [
                'columns' => $rows->sortBy('Seq_in_index')->pluck('Column_name')->values()->all(),
                'unique' => (int) $rows->first()->Non_unique === 0,
            ]);

        $found = $indexes->contains(fn ($index) => array_slice($index['columns'], 0, count($columns)) === $columns
            && (! $unique || $index['unique'])
        );

        $this->assertTrue($found, "Missing expected index on {$table} (".implode(', ', $columns).').');
    }
}
