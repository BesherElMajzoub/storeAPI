<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The frontend demo dataset is disabled in production.');
        }

        $this->call([
            DemoAccessSeeder::class,
            DemoCatalogSeeder::class,
            DemoCommerceSeeder::class,
            DemoEngagementSeeder::class,
            DemoAnalyticsSeeder::class,
        ]);
    }
}
