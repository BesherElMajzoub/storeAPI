<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CleanupDemoData extends Command
{
    protected $signature = 'app:cleanup-demo-data
        {--execute : Delete the selected data instead of showing a dry run}
        {--backup-reference= : Required backup or restore-point reference when executing}
        {--order=* : Additional owner-approved order number to delete}
        {--product=* : Additional owner-approved product ID to delete}';

    protected $description = 'Safely identify and optionally remove recognized demo fixtures';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $backupReference = trim((string) $this->option('backup-reference'));

        if ($execute && $backupReference === '') {
            $this->error('Execution requires --backup-reference=<reference>. No data was changed.');

            return self::FAILURE;
        }

        try {
            $targets = $this->targets();
            $this->assertSafeDependencies($targets);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $this->error('No data was changed.');

            return self::FAILURE;
        }

        $before = $this->counts($targets);
        $this->line($execute ? 'EXECUTION MODE' : 'DRY RUN — no data will be changed');
        if ($execute) {
            $this->line('Backup reference: '.$backupReference);
        }
        $this->table(['Target', 'Selected before'], $this->countRows($before));

        if (! $execute) {
            $this->info('Re-run with --execute and --backup-reference after reviewing these counts.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($targets): void {
            $this->deleteModels($targets['orders'], force: true);
            $this->deleteModels($targets['coupons']);
            $this->deleteModels($targets['campaigns']);
            $this->deleteModels($targets['posts']);
            $this->deleteModels($targets['visitors']);
            $this->deleteModels($targets['products'], force: true);
            $this->deleteModels($targets['categories'], force: true);
            $this->deleteModels($targets['users']);
        });

        $after = $this->counts($this->targets());
        $this->table(['Target', 'Selected before', 'Remaining after'], collect($before)
            ->map(fn (int $count, string $name) => [$name, $count, $after[$name]])
            ->values()->all());
        $this->info('Demo cleanup completed. Run php artisan app:production-readiness next.');

        return self::SUCCESS;
    }

    /** @return array<string, Builder> */
    private function targets(): array
    {
        $approvedOrders = collect((array) $this->option('order'))->filter()->unique()->values()->all();
        $approvedProducts = collect((array) $this->option('product'))
            ->filter(fn ($id) => ctype_digit((string) $id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        return [
            'orders' => Order::withTrashed()->where(fn (Builder $query) => $query
                ->where('order_number', 'like', 'DEMO-%')
                ->when($approvedOrders, fn (Builder $q) => $q->orWhereIn('order_number', $approvedOrders))),
            'coupons' => Coupon::query()->where('code', 'like', 'DEMO-%'),
            'campaigns' => Campaign::query()->where('name', 'like', 'Demo %'),
            'posts' => Post::query()->where('slug', 'like', 'demo-%'),
            'visitors' => Visitor::query()->where('user_agent', 'like', 'DemoBrowser/%'),
            'products' => Product::withTrashed()
                ->where(fn (Builder $query) => $query
                    ->where('sku', 'like', 'DEMO-%')
                    ->orWhere('slug', 'like', 'demo-product-%')
                    ->when($approvedProducts, fn (Builder $q) => $q->orWhereIn('id', $approvedProducts)))
                ->when(! in_array(102, $approvedProducts, true), fn (Builder $query) => $query->whereKeyNot(102)),
            'categories' => Category::withTrashed()->where('slug', 'like', 'demo-%'),
            'users' => User::query()->where('email', 'like', '%@demo.test'),
        ];
    }

    /** @param array<string, Builder> $targets */
    private function assertSafeDependencies(array $targets): void
    {
        $demoCategoryIds = (clone $targets['categories'])->pluck('id');
        $selectedProductIds = (clone $targets['products'])->pluck('id');
        $unexpectedProducts = Product::withTrashed()
            ->whereIn('category_id', $demoCategoryIds)
            ->whereNotIn('id', $selectedProductIds)
            ->pluck('id');

        if ($unexpectedProducts->isNotEmpty()) {
            throw new RuntimeException('Blocked: demo categories contain non-demo products: '.$unexpectedProducts->implode(', '));
        }

        $unexpectedChildren = Category::withTrashed()
            ->whereIn('parent_id', $demoCategoryIds)
            ->whereNotIn('id', $demoCategoryIds)
            ->pluck('id');

        if ($unexpectedChildren->isNotEmpty()) {
            throw new RuntimeException('Blocked: demo categories contain non-demo child categories: '.$unexpectedChildren->implode(', '));
        }

        $demoUserIds = (clone $targets['users'])->pluck('id');
        $selectedOrderIds = (clone $targets['orders'])->pluck('id');
        $unexpectedOrders = Order::withTrashed()
            ->whereIn('user_id', $demoUserIds)
            ->whereNotIn('id', $selectedOrderIds)
            ->pluck('order_number');

        if ($unexpectedOrders->isNotEmpty()) {
            throw new RuntimeException('Blocked: demo accounts own non-demo orders: '.$unexpectedOrders->implode(', '));
        }
    }

    /** @param array<string, Builder> $targets
     * @return array<string, int>
     */
    private function counts(array $targets): array
    {
        return collect($targets)->map(fn (Builder $query) => (clone $query)->count())->all();
    }

    /** @param array<string, int> $counts */
    private function countRows(array $counts): array
    {
        return collect($counts)->map(fn (int $count, string $name) => [$name, $count])->values()->all();
    }

    private function deleteModels(Builder $query, bool $force = false): void
    {
        $query->get()->each(function ($model) use ($force): void {
            $force ? $model->forceDelete() : $model->delete();
        });
    }
}
