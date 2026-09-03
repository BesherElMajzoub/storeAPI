<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo database seeders are disabled in production.');
        }

        $this->call([
            RolesAndAdminSeeder::class,
            AdminSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            ProductVariantSeeder::class,
            CouponSeeder::class,
            AddressSeeder::class,
            OrderSeeder::class,
            OrderCancellationRequestSeeder::class,
            ReviewSeeder::class,
            WishlistSeeder::class,
            ContactMessageSeeder::class,
        ]);
    }
}
