<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The only seeder meant for production: reference data, no demo records.
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Idempotent, so ops/deploy-production.sh runs it on every deploy.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
