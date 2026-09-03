<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProductionReadinessCheck extends Command
{
    protected $signature = 'app:production-readiness';

    protected $description = 'Run non-destructive production configuration and data safety checks';

    public function handle(): int
    {
        $checks = [
            ['Production environment', app()->environment('production'), app()->environment()],
            ['Debug disabled', ! config('app.debug'), config('app.debug') ? 'enabled' : 'disabled'],
            ['HTTPS application URL', Str::startsWith((string) config('app.url'), 'https://'), (string) config('app.url')],
            ['HTTPS frontend URL', Str::startsWith((string) config('app.frontend_url'), 'https://'), (string) config('app.frontend_url')],
            ['Application key configured', filled(config('app.key')), filled(config('app.key')) ? 'configured' : 'missing'],
            ['Sanctum token expiry', (int) config('sanctum.expiration') > 0, (string) config('sanctum.expiration').' minutes'],
            ['Stripe live secret', Str::startsWith((string) config('services.stripe.secret'), 'sk_live_'), $this->masked(config('services.stripe.secret'))],
            ['Stripe webhook secret', Str::startsWith((string) config('services.stripe.webhook_secret'), 'whsec_'), $this->masked(config('services.stripe.webhook_secret'))],
            ['EasyPost configured', filled(config('services.easypost.api_key')) && filled(config('services.easypost.webhook_secret')), filled(config('services.easypost.api_key')) ? 'configured' : 'missing'],
            ['Warehouse origin configured', $this->warehouseOriginIsConfigured(), config('services.store_origin.street1', 'missing')],
            ['Shipping packages configured', count(config('services.easypost.packages', [])) > 0, count(config('services.easypost.packages', [])).' package(s)'],
            ['Durable queue enabled', config('queue.default') !== 'sync', (string) config('queue.default')],
            ['Production mailer enabled', ! in_array(config('mail.default'), ['log', 'array'], true), (string) config('mail.default')],
            ['Telescope disabled', ! config('telescope.enabled'), config('telescope.enabled') ? 'enabled' : 'disabled'],
            ['UTC timestamps', config('app.timezone') === 'UTC', (string) config('app.timezone')],
            ['MySQL utf8mb4', config('database.default') !== 'mysql' || config('database.connections.mysql.charset') === 'utf8mb4', (string) config('database.connections.mysql.charset')],
            ['No known demo accounts', $this->demoAccountCount() === 0, $this->demoAccountCount().' found'],
            ['No known demo catalogue', $this->demoContentCount() === 0, $this->demoContentCount().' found'],
            ['No test/debug routes', ! $this->hasTestRoutes(), $this->hasTestRoutes() ? 'found' : 'none'],
        ];

        $this->table(['Check', 'Result', 'Detail'], array_map(fn ($check) => [
            $check[0], $check[1] ? 'PASS' : 'FAIL', $check[2],
        ], $checks));

        $failed = collect($checks)->contains(fn ($check) => ! $check[1]);
        if ($failed) {
            $this->error('Production readiness checks failed. No data was changed.');

            return self::FAILURE;
        }

        $this->info('All automated production readiness checks passed.');

        return self::SUCCESS;
    }

    private function demoAccountCount(): int
    {
        return User::query()->whereIn('email', [
            'admin@store.com', 'user@store.com', 'owner@store.com', 'manager@store.com',
            'support@store.com', 'customer@store.com', 'user_with_address@store.com',
            'user_no_address@store.com', 'user_with_orders@store.com', 'user_no_orders@store.com',
            'user_cancelled_orders@store.com', 'user_many_orders@store.com',
            'user_wishlist@store.com', 'user_empty_wishlist@store.com',
        ])->count();
    }

    private function hasTestRoutes(): bool
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => preg_match('#(^|/)(test|debug|phpinfo)(/|$)#i', $route->uri()));
    }

    private function demoContentCount(): int
    {
        return Product::withTrashed()->where('name', 'like', 'Extra Demo Product%')->count()
            + Category::withTrashed()->whereIn('slug', ['electronics', 'empty-category'])->count();
    }

    private function warehouseOriginIsConfigured(): bool
    {
        $origin = config('services.store_origin', []);

        return collect(['street1', 'city', 'state', 'zip', 'country'])
            ->every(fn (string $field) => filled($origin[$field] ?? null))
            && ($origin['street1'] ?? null) !== '123 Main Street'
            && strtoupper((string) ($origin['country'] ?? '')) === 'US';
    }

    private function masked(?string $value): string
    {
        return $value ? substr($value, 0, 7).'***' : 'missing';
    }
}
