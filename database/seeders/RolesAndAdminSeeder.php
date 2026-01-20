<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles
        $roles = [
            'Owner', 'Admin', 'Manager', 'Support', 'User'
        ];
        
        foreach ($roles as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
        
        // 2. Permissions (Examples)
        $permissions = [
            'view_dashboard', 'manage_users', 'manage_products', 'manage_orders'
        ];
        
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        
        // 3. Assign Permissions (Simplified - Admin gets all)
        // ...
        
        // 4. Create Admin User
        $adminRole = Role::where('name', 'Admin')->first();
        
        $admin = User::firstOrCreate([
            'email' => 'admin@store.com'
        ], [
            'name' => 'Admin User',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);
        
        if (!$admin->roles->contains($adminRole->id)) {
            $admin->roles()->attach($adminRole);
        }
        
        // Create a regular user
        User::firstOrCreate([
            'email' => 'user@store.com'
        ], [
            'name' => 'Demo User',
            'password' => Hash::make('password123'),
        ]);
    }
}
