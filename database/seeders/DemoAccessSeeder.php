<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DemoAccessSeeder extends DemoSeeder
{
    public const PASSWORD = 'Demo1234!';

    public function run(): void
    {
        $this->guardAgainstProduction();

        $permissions = collect([
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
        ])->mapWithKeys(function (string $name) {
            $permission = Permission::updateOrCreate(['name' => $name]);

            return [$name => $permission];
        });

        $roleDefinitions = [
            'Owner' => ['label' => 'Store owner', 'permissions' => $permissions->keys()->all()],
            'Admin' => ['label' => 'Administrator', 'permissions' => $permissions->keys()->all()],
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

        $roles = collect($roleDefinitions)->mapWithKeys(function (array $definition, string $name) use ($permissions) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => $definition['label']]);
            $role->permissions()->sync($permissions->only($definition['permissions'])->pluck('id'));

            return [$name => $role];
        });

        $definitions = [
            ['name' => 'Demo Owner', 'email' => 'owner@demo.test', 'phone' => '+12025550101', 'role' => 'Owner'],
            ['name' => 'Demo Administrator', 'email' => 'admin@demo.test', 'phone' => '+12025550102', 'role' => 'Admin'],
            ['name' => 'Catalogue Manager', 'email' => 'manager@demo.test', 'phone' => '+12025550103', 'role' => 'Manager'],
            ['name' => 'Customer Support Agent', 'email' => 'support@demo.test', 'phone' => '+12025550104', 'role' => 'Support'],
            ['name' => 'Full Profile Customer', 'email' => 'customer.full@demo.test', 'phone' => '+12025550201', 'role' => 'User'],
            ['name' => 'Empty State Customer', 'email' => 'customer.empty@demo.test', 'phone' => '+12025550202', 'role' => 'User', 'no_address' => true],
            ['name' => 'Unverified Customer', 'email' => 'customer.unverified@demo.test', 'phone' => '+12025550203', 'role' => 'User', 'verified' => false],
            ['name' => 'Inactive Customer', 'email' => 'customer.inactive@demo.test', 'phone' => '+12025550204', 'role' => 'User', 'active' => false],
            ['name' => 'Customer With Many Orders', 'email' => 'customer.orders@demo.test', 'phone' => '+12025550205', 'role' => 'User'],
            ['name' => 'Customer With Large Wishlist', 'email' => 'customer.wishlist@demo.test', 'phone' => '+12025550206', 'role' => 'User'],
            ['name' => 'Refund Scenario Customer', 'email' => 'customer.refunds@demo.test', 'phone' => '+12025550207', 'role' => 'User'],
            ['name' => 'Tracking Scenario Customer', 'email' => 'customer.tracking@demo.test', 'phone' => '+12025550208', 'role' => 'User'],
            ['name' => 'Google Login Customer', 'email' => 'customer.google@demo.test', 'phone' => '+12025550209', 'role' => 'User'],
            ['name' => 'عميلة تجريبية باسم عربي طويل لاختبار التفاف النص في القوائم والبطاقات', 'email' => 'customer.arabic@demo.test', 'phone' => '+12025550210', 'role' => 'User'],
        ];

        for ($index = 1; $index <= 10; $index++) {
            $definitions[] = [
                'name' => sprintf('Pagination Customer %02d', $index),
                'email' => sprintf('customer%02d@demo.test', $index),
                'phone' => sprintf('+120255503%02d', $index),
                'role' => 'User',
            ];
        }

        foreach ($definitions as $index => $definition) {
            $verified = $definition['verified'] ?? true;
            $active = $definition['active'] ?? true;
            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'phone' => $definition['phone'],
                    'password' => Hash::make(self::PASSWORD),
                    'avatar_url' => $index % 4 === 0 ? null : '/storage/demo/avatars/avatar-'.(($index % 8) + 1).'.png',
                    'is_active' => $active,
                ]
            );
            $user->forceFill([
                'email_verified_at' => $verified ? now()->subMonths(2) : null,
                'phone_verified_at' => $verified && $index % 3 !== 0 ? now()->subMonth() : null,
                'last_login_at' => $active ? now()->subHours(($index % 72) + 1) : now()->subMonths(3),
                'last_login_ip' => '203.0.113.'.(($index % 200) + 10),
            ])->saveQuietly();
            $user->roles()->sync([$roles[$definition['role']]->id]);

            if (! ($definition['no_address'] ?? false) && $definition['role'] === 'User') {
                $this->seedAddresses($user, $index);
            }
        }

        $googleUser = User::where('email', 'customer.google@demo.test')->firstOrFail();
        SocialAccount::updateOrCreate(
            ['provider' => 'google', 'provider_id' => 'demo-google-customer-001'],
            [
                'user_id' => $googleUser->id,
                'provider_email' => $googleUser->email,
                'avatar_url' => '/storage/demo/avatars/google-customer.png',
                'provider_data' => ['email_verified' => true, 'locale' => 'en'],
            ]
        );

        $this->command?->info('Demo access data: 5 roles and 24 users with varied auth/profile states.');
    }

    private function seedAddresses(User $user, int $index): void
    {
        $locations = [
            ['city' => 'New York', 'state' => 'NY', 'postal_code' => '10001', 'line1' => '350 Fifth Avenue'],
            ['city' => 'Pasadena', 'state' => 'CA', 'postal_code' => '91101', 'line1' => '123 Main Street'],
            ['city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'line1' => '600 Congress Avenue'],
            ['city' => 'Seattle', 'state' => 'WA', 'postal_code' => '98101', 'line1' => '1420 Fifth Avenue'],
            ['city' => 'Miami', 'state' => 'FL', 'postal_code' => '33101', 'line1' => '100 Biscayne Boulevard'],
        ];
        $location = $locations[$index % count($locations)];

        Address::updateOrCreate(
            ['user_id' => $user->id, 'label' => 'home'],
            [
                'type' => 'shipping',
                'name' => 'Home',
                'full_name' => $user->name,
                'phone' => $user->phone,
                'line1' => $location['line1'],
                'line2' => $index % 2 === 0 ? 'Apartment '.($index + 10) : null,
                'country' => 'US',
                'city' => $location['city'],
                'state' => $location['state'],
                'area' => 'Downtown',
                'street' => $location['line1'],
                'building' => (string) (100 + $index),
                'floor' => $index % 2 === 0 ? (string) (($index % 12) + 1) : null,
                'apartment' => $index % 2 === 0 ? (string) ($index + 10) : null,
                'postal_code' => $location['postal_code'],
                'notes' => $index % 3 === 0 ? 'Ring the bell once; leave with reception if unavailable.' : null,
                'is_default' => true,
            ]
        );

        if ($index % 2 === 0) {
            Address::updateOrCreate(
                ['user_id' => $user->id, 'label' => 'work'],
                [
                    'type' => 'billing',
                    'name' => 'Work',
                    'full_name' => $user->name.' — Office',
                    'phone' => $user->phone,
                    'line1' => '1 Market Street',
                    'line2' => 'Suite '.(200 + $index),
                    'country' => 'US',
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'area' => 'Financial District',
                    'street' => 'Market Street',
                    'building' => '1',
                    'floor' => '2',
                    'apartment' => (string) (200 + $index),
                    'postal_code' => '94105',
                    'notes' => 'Deliver Monday through Friday, 09:00–17:00.',
                    'is_default' => false,
                ]
            );
        }
    }
}
