<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use App\Services\Admin\AdminAccountManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_account_management_page(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin/accounts');

        $response->assertOk();
        $response->assertSee('Account Management');
        $response->assertSee('Daftar Akun Admin');
    }

    public function test_admin_cannot_access_account_management_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/accounts');

        $response->assertStatus(403);
    }

    public function test_regular_user_cannot_access_account_management_page(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($user)->get('/admin/accounts');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_super_admin_can_create_admin_account(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $created = $service->create($superAdmin, [
            'name' => 'Admin Baru',
            'email' => 'admin-baru@instansi.go.id',
            'password' => 'temporary-pass-1234',
            'role' => User::ROLE_ADMIN,
            'force_password_change' => true,
        ]);

        $this->assertEquals(User::ROLE_ADMIN, $created->role);
        $this->assertTrue((bool) $created->is_active);
        $this->assertTrue((bool) $created->force_password_change);
        $this->assertDatabaseHas('admin_account_audits', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $created->id,
            'action' => AdminAccountAudit::ACTION_CREATED,
        ]);
    }

    public function test_super_admin_cannot_create_with_duplicate_email(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $existing = User::factory()->create(['email' => 'duplicate@instansi.go.id']);

        $this->expectException(ValidationException::class);

        $service->create($superAdmin, [
            'name' => 'Admin Baru',
            'email' => $existing->email,
            'password' => 'temporary-pass-1234',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_super_admin_can_update_admin_role(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service->update($superAdmin, $target, [
            'name' => $target->name,
            'email' => $target->email,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $target->refresh();
        $this->assertEquals(User::ROLE_SUPER_ADMIN, $target->role);
        $this->assertDatabaseHas('admin_account_audits', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'action' => AdminAccountAudit::ACTION_ROLE_CHANGED,
        ]);
    }

    public function test_super_admin_can_deactivate_other_admin(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service->deactivate($superAdmin, $target, 'mutasi');

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNotNull($target->disabled_at);
        $this->assertEquals($superAdmin->id, $target->disabled_by);
        $this->assertDatabaseHas('admin_account_audits', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'action' => AdminAccountAudit::ACTION_DEACTIVATED,
        ]);
    }

    public function test_super_admin_cannot_deactivate_self(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        // Add another super admin so the "last super admin" guard isn't the trigger
        User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $service->deactivate($superAdmin, $superAdmin);
    }

    public function test_cannot_deactivate_last_super_admin(): void
    {
        $service = app(AdminAccountManagementService::class);
        $solo = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        // Use a separate actor super admin to bypass self-deactivate guard.
        $actor = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        // Now deactivate $actor first, leaving $solo as the only active super admin.
        $service->deactivate($solo, $actor);

        $this->assertEquals(1, $service->activeSuperAdminsCount());

        $this->expectException(ValidationException::class);
        // Attempt to deactivate the remaining super admin via another super admin actor: not possible (only one left).
        // Use solo as actor to demonstrate the guard against deactivating the last one.
        $service->deactivate($solo, $solo);
    }

    public function test_super_admin_can_reset_password_and_force_change(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => Hash::make('old-password'),
            'force_password_change' => false,
        ]);

        $service->resetPassword($superAdmin, $target, 'new-temp-1234');

        $target->refresh();
        $this->assertTrue(Hash::check('new-temp-1234', $target->password));
        $this->assertTrue((bool) $target->force_password_change);
        $this->assertDatabaseHas('admin_account_audits', [
            'actor_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'action' => AdminAccountAudit::ACTION_PASSWORD_RESET,
        ]);
    }

    public function test_admin_cannot_perform_account_management(): void
    {
        $service = app(AdminAccountManagementService::class);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $target = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->update($admin, $target, ['name' => 'X']);
    }

    public function test_super_admin_cannot_demote_last_super_admin(): void
    {
        $service = app(AdminAccountManagementService::class);
        $solo = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $service->update($solo, $solo, [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_audit_does_not_persist_password(): void
    {
        $service = app(AdminAccountManagementService::class);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $created = $service->create($superAdmin, [
            'name' => 'Admin Test',
            'email' => 'audit-pass@instansi.go.id',
            'password' => 'rahasia-banget-1234',
            'role' => User::ROLE_ADMIN,
        ]);

        $audit = AdminAccountAudit::query()
            ->where('target_user_id', $created->id)
            ->where('action', AdminAccountAudit::ACTION_CREATED)
            ->firstOrFail();

        $serialized = json_encode([
            'before' => $audit->before_snapshot,
            'after' => $audit->after_snapshot,
            'metadata' => $audit->metadata,
        ]);

        $this->assertStringNotContainsString('rahasia-banget-1234', (string) $serialized);
        // Snapshot must not contain a hashed password under the literal "password" key
        $this->assertArrayNotHasKey('password', (array) $audit->after_snapshot);
        $this->assertArrayNotHasKey('password', (array) ($audit->before_snapshot ?? []));
        $this->assertArrayNotHasKey('password', (array) ($audit->metadata ?? []));
    }
}
