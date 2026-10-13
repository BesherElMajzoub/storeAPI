<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_in_production_and_creates_only_reference_data(): void
    {
        $this->app['env'] = 'production';

        // Same invocation as ops/deploy-production.sh.
        $this->artisan('db:seed', ['--class' => ProductionSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(array_keys(RolesAndPermissionsSeeder::ROLES), Role::pluck('name')->all());
        $this->assertSame(count(RolesAndPermissionsSeeder::PERMISSIONS), Permission::count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame(
            count(RolesAndPermissionsSeeder::PERMISSIONS),
            Role::where('name', 'Owner')->firstOrFail()->permissions()->count()
        );
    }

    public function test_is_idempotent_and_keeps_existing_role_changes(): void
    {
        $this->seed(ProductionSeeder::class);
        $support = Role::where('name', 'Support')->firstOrFail();
        $support->update(['label' => 'Customer care']);
        $support->permissions()->attach(Permission::where('name', 'audit.view')->value('id'));

        $this->seed(ProductionSeeder::class);

        $this->assertSame(count(RolesAndPermissionsSeeder::ROLES), Role::count());
        $this->assertSame(count(RolesAndPermissionsSeeder::PERMISSIONS), Permission::count());
        $support->refresh();
        $this->assertSame('Customer care', $support->label);
        $this->assertTrue($support->permissions()->where('name', 'audit.view')->exists());
    }

    public function test_staff_account_can_be_created_after_seeding(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->artisan('app:create-admin', ['email' => 'owner@example.com', 'name' => 'Store Owner', '--role' => 'Owner'])
            ->expectsQuestion('Password (min 12 chars, upper + lower case, a number)', 'Owner-Pass-2026')
            ->expectsQuestion('Confirm password', 'Owner-Pass-2026')
            ->assertSuccessful();
    }
}
