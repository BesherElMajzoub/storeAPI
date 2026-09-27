<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UnpublishProductsMissingShippingData extends Command
{
    protected $signature = 'products:unpublish-missing-shipping-data
        {--apply : Actually move the products to draft. Without this flag, only a preview is shown.}';

    protected $description = 'Move published products with no weight/dimensions to draft so they never block checkout or the readiness gate.';

    public function handle(): int
    {
        $products = Product::query()
            ->where('status', 'published')
            ->where(function ($query): void {
                foreach (['weight_oz', 'length_in', 'width_in', 'height_in'] as $field) {
                    $query->orWhereNull($field)->orWhere($field, '<=', 0);
                }
            })
            ->orderBy('id')
            ->get(['id', 'sku', 'name']);

        if ($products->isEmpty()) {
            $this->info('No published products are missing shipping data.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'SKU', 'Name'], $products->map(fn (Product $p) => [$p->id, $p->sku, $p->name])->all());

        if (! $this->option('apply')) {
            $this->warn("{$products->count()} product(s) would be moved to draft. Re-run with --apply to make the change.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($products): void {
            Product::query()->whereKey($products->pluck('id'))->update(['status' => 'draft']);

            AuditLog::create([
                'action' => 'unpublished_products_missing_shipping_data',
                'description' => "Moved {$products->count()} product(s) missing shipping data to draft via console",
                'changes' => ['product_ids' => $products->pluck('id')->all(), 'skus' => $products->pluck('sku')->all()],
            ]);
        });

        $this->info("Moved {$products->count()} product(s) to draft.");

        return self::SUCCESS;
    }
}
