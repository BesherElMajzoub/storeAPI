<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $admins = [
            [
                'email' => 'admin@store.com',
                'name' => 'Default Admin',
                'role' => 'Admin',
            ],
            [
                'email' => 'owner@store.com',
                'name' => 'Default Owner',
                'role' => 'Owner',
            ],
            [
                'email' => 'manager@store.com',
                'name' => 'Default Manager',
                'role' => 'Manager',
            ],
            [
                'email' => 'support@store.com',
                'name' => 'Default Support',
                'role' => 'Support',
            ],
        ];

        foreach ($admins as $adminData) {
            $user = User::updateOrCreate(
                ['email' => $adminData['email']],
                [
                    'name' => $adminData['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $role = Role::where('name', $adminData['role'])->first();
            if ($role && ! $user->roles()->whereKey($role->id)->exists()) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }
    }
}
