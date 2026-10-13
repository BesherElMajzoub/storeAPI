<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Reference data the application cannot run without: staff roles (admin
 * access, app:create-admin) and the "User" role given to new customers.
 *
 * Production-safe and idempotent: it only adds what is missing and never
 * removes permissions or changes roles that already exist.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard.view',
        'analytics.view',
        'products.manage',
        'categories.manage',
        'orders.manage',
        'shipping.manage',
        'coupons.manage',
        'reviews.moderate',
        'customers.view',
        'messages.manage',
        'settings.manage',
        'audit.view',
    ];

    public const ROLES = [
        'Owner' => ['label' => 'Store owner', 'permissions' => self::PERMISSIONS],
        'Admin' => ['label' => 'Administrator', 'permissions' => self::PERMISSIONS],
        'Manager' => [
            'label' => 'Catalogue and order manager',
            'permissions' => ['dashboard.view', 'analytics.view', 'products.manage', 'categories.manage', 'orders.manage', 'shipping.manage', 'coupons.manage', 'reviews.moderate', 'customers.view'],
        ],
        'Support' => [
            'label' => 'Customer support',
            'permissions' => ['dashboard.view', 'orders.manage', 'shipping.manage', 'reviews.moderate', 'customers.view', 'messages.manage'],
        ],
        'User' => ['label' => 'Customer', 'permissions' => []],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->mapWithKeys(fn (string $name) => [$name => Permission::firstOrCreate(['name' => $name])]);

        foreach (self::ROLES as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $definition['label']]);
            $role->permissions()->syncWithoutDetaching(
                $permissions->only($definition['permissions'])->pluck('id')
            );
        }

        $this->command?->info('Roles and permissions: '.count(self::ROLES).' roles, '.count(self::PERMISSIONS).' permissions ensured.');
    }
}
