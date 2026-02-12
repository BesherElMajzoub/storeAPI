<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Roles
        $roles = ['Owner', 'Admin', 'Manager', 'Support', 'User'];

        foreach ($roles as $name) {
            Role::firstOrCreate(['name' => $name]);
        }

        // 2) Permissions
        $permissions = ['view_dashboard', 'manage_users', 'manage_products', 'manage_orders'];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // 3) Create Admin User
        $adminRole = Role::where('name', 'Admin')->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@store.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        if ($adminRole && ! $admin->roles()->whereKey($adminRole->id)->exists()) {
            $admin->roles()->attach($adminRole->id);
        }

        // 4) Regular User
        User::firstOrCreate(
            ['email' => 'user@store.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password123'),
            ]
        );
    }
}
