<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email' => 'customer@store.com',
                'name'  => 'Standard Customer',
            ],
            [
                'email' => 'user_with_address@store.com',
                'name'  => 'Customer with Address',
            ],
            [
                'email' => 'user_no_address@store.com',
                'name'  => 'Customer without Address',
            ],
            [
                'email' => 'user_with_orders@store.com',
                'name'  => 'Customer with Orders',
            ],
            [
                'email' => 'user_no_orders@store.com',
                'name'  => 'Customer without Orders',
            ],
            [
                'email' => 'user_cancelled_orders@store.com',
                'name'  => 'Customer with Cancelled Orders',
            ],
            [
                'email' => 'user_many_orders@store.com',
                'name'  => 'Customer with Many Orders',
            ],
            [
                'email' => 'user_wishlist@store.com',
                'name'  => 'Customer with Wishlist Items',
            ],
            [
                'email' => 'user_empty_wishlist@store.com',
                'name'  => 'Customer with Empty Wishlist',
            ],
        ];

        $userRole = Role::firstOrCreate(['name' => 'User']);

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name'              => $userData['name'],
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            if ($userRole && !$user->roles()->whereKey($userRole->id)->exists()) {
                $user->roles()->syncWithoutDetaching([$userRole->id]);
            }
        }
    }
}
