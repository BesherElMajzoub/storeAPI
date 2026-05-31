<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            [
                'code'                     => 'WELCOME20',
                'type'                     => 'percentage',
                'value'                    => 20.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => 50.00,
                'usage_limit'              => 100,
                'used_count'               => 0,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'SAVE50',
                'type'                     => 'fixed',
                'value'                    => 50.00,
                'minimum_order_amount'     => 100.00,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 500,
                'used_count'               => 0,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'EXPIRED10',
                'type'                     => 'percentage',
                'value'                    => 10.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 100,
                'used_count'               => 2,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subMonth(),
                'expires_at'               => now()->subDay(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'FUTURE15',
                'type'                     => 'percentage',
                'value'                    => 15.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 100,
                'used_count'               => 0,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->addMonth(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'LIMITED5',
                'type'                     => 'fixed',
                'value'                    => 10.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 5,
                'used_count'               => 5, // Usage limit reached
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'USERONLY10',
                'type'                     => 'fixed',
                'value'                    => 10.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 1000,
                'used_count'               => 10,
                'usage_limit_per_user'     => 1, // Per-user limit
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'MIN200',
                'type'                     => 'fixed',
                'value'                    => 30.00,
                'minimum_order_amount'     => 200.00, // Minimum order limit
                'maximum_discount_amount'  => null,
                'usage_limit'              => 100,
                'used_count'               => 0,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => true,
            ],
            [
                'code'                     => 'DISABLED25',
                'type'                     => 'percentage',
                'value'                    => 25.00,
                'minimum_order_amount'     => null,
                'maximum_discount_amount'  => null,
                'usage_limit'              => 100,
                'used_count'               => 0,
                'usage_limit_per_user'     => null,
                'starts_at'                => now()->subDay(),
                'expires_at'               => now()->addYear(),
                'is_active'                => false, // Inactive
            ],
        ];

        foreach ($coupons as $coup) {
            Coupon::updateOrCreate(
                ['code' => $coup['code']],
                $coup
            );
        }
    }
}
