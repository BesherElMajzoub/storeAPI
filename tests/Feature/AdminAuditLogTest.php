<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_audit_logs_and_sensitive_change_keys_are_redacted(): void
    {
        $admin = User::factory()->create(['name' => 'Audit Admin']);
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $match = AuditLog::create([
            'causer_id' => $admin->id,
            'causer_type' => User::class,
            'action' => 'updated_product',
            'description' => 'Updated SKU TEST-1',
            'ip_address' => '127.0.0.1',
            'changes' => [
                'status' => ['old' => 'draft', 'new' => 'published'],
                'password' => 'must-not-leak',
                'nested' => ['api_token' => 'must-not-leak'],
            ],
        ]);
        AuditLog::create([
            'action' => 'deleted_coupon',
            'description' => 'Deleted coupon',
            'ip_address' => '127.0.0.2',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/audit-logs?action=updated_product&causer_id={$admin->id}&search=TEST-1")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $match->id)
            ->assertJsonPath('data.data.0.causer.name', 'Audit Admin')
            ->assertJsonPath('data.data.0.changes.status.new', 'published')
            ->assertJsonPath('data.data.0.changes.password', '[REDACTED]')
            ->assertJsonPath('data.data.0.changes.nested.api_token', '[REDACTED]');
    }

    public function test_regular_customer_cannot_read_audit_logs(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();
    }
}
