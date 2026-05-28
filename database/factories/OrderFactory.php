<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'ORD-' . strtoupper(Str::random(10)),
            'user_id' => User::factory(),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 100.00,
            'total' => 100.00,
            'shipping_address' => [
                'line1' => '123 Main St',
                'city' => 'New York',
                'country' => 'US',
            ],
            'billing_address' => [
                'line1' => '123 Main St',
                'city' => 'New York',
                'country' => 'US',
            ],
        ];
    }
}
