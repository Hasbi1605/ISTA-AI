<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_env_credentials_missing(): void
    {
        config([
            'app.env' => 'testing',
        ]);

        // Ensure env getters return null
        putenv('INITIAL_ADMIN_EMAIL');
        putenv('INITIAL_ADMIN_PASSWORD');
        putenv('INITIAL_SUPER_ADMIN_EMAIL');
        putenv('INITIAL_SUPER_ADMIN_PASSWORD');

        $exitCode = Artisan::call('admin:bootstrap-accounts');
        $this->assertEquals(1, $exitCode);
    }

    public function test_command_creates_admin_and_super_admin_from_env(): void
    {
        putenv('INITIAL_ADMIN_EMAIL=admin@example.go.id');
        putenv('INITIAL_ADMIN_PASSWORD=temp-pass-1234');
        putenv('INITIAL_SUPER_ADMIN_EMAIL=superadmin@example.go.id');
        putenv('INITIAL_SUPER_ADMIN_PASSWORD=super-temp-1234');

        $exitCode = Artisan::call('admin:bootstrap-accounts');
        $this->assertEquals(0, $exitCode);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.go.id',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'superadmin@example.go.id',
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);

        $this->assertDatabaseHas('admin_account_audits', [
            'action' => AdminAccountAudit::ACTION_CREATED,
        ]);
    }

    public function test_command_is_idempotent_for_existing_users(): void
    {
        putenv('INITIAL_ADMIN_EMAIL=admin@example.go.id');
        putenv('INITIAL_ADMIN_PASSWORD=temp-pass-1234');
        putenv('INITIAL_SUPER_ADMIN_EMAIL=superadmin@example.go.id');
        putenv('INITIAL_SUPER_ADMIN_PASSWORD=super-temp-1234');

        Artisan::call('admin:bootstrap-accounts');
        $countBefore = User::count();

        Artisan::call('admin:bootstrap-accounts');
        $this->assertEquals($countBefore, User::count());
    }

    public function test_command_force_reset_password_updates_password(): void
    {
        putenv('INITIAL_ADMIN_EMAIL=admin@example.go.id');
        putenv('INITIAL_ADMIN_PASSWORD=initial-pass-1234');
        putenv('INITIAL_SUPER_ADMIN_EMAIL=superadmin@example.go.id');
        putenv('INITIAL_SUPER_ADMIN_PASSWORD=initial-super-1234');

        Artisan::call('admin:bootstrap-accounts');

        $admin = User::where('email', 'admin@example.go.id')->first();
        $admin->forceFill(['password' => Hash::make('manually-changed')])->save();

        // Re-run with force reset and a new password
        putenv('INITIAL_ADMIN_PASSWORD=new-reset-pass-1234');
        Artisan::call('admin:bootstrap-accounts', ['--force-reset-password' => true]);

        $admin->refresh();
        $this->assertTrue(Hash::check('new-reset-pass-1234', $admin->password));
        $this->assertTrue((bool) $admin->force_password_change);
    }
}
