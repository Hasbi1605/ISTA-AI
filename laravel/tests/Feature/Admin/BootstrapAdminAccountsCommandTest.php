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

    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot and clear admin bootstrap env vars so dotenv values from
        // .env do not leak into the test (env() reads $_ENV/$_SERVER first).
        foreach ([
            'INITIAL_ADMIN_EMAIL',
            'INITIAL_ADMIN_PASSWORD',
            'INITIAL_SUPER_ADMIN_EMAIL',
            'INITIAL_SUPER_ADMIN_PASSWORD',
        ] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? false;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    /**
     * Set the four bootstrap env vars in a way that env() will pick up.
     */
    private function setBootstrapEnv(string $admin, string $adminPass, string $superAdmin, string $superAdminPass): void
    {
        $values = [
            'INITIAL_ADMIN_EMAIL' => $admin,
            'INITIAL_ADMIN_PASSWORD' => $adminPass,
            'INITIAL_SUPER_ADMIN_EMAIL' => $superAdmin,
            'INITIAL_SUPER_ADMIN_PASSWORD' => $superAdminPass,
        ];
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    public function test_command_fails_when_env_credentials_missing(): void
    {
        $exitCode = Artisan::call('admin:bootstrap-accounts');
        $this->assertEquals(1, $exitCode);
    }

    public function test_command_creates_admin_and_super_admin_from_env(): void
    {
        $this->setBootstrapEnv(
            'admin@example.go.id',
            'temp-pass-1234',
            'superadmin@example.go.id',
            'super-temp-1234',
        );

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

        // Bootstrap accounts must be email-verified so the `verified` middleware
        // does not block them on /admin/* routes.
        $admin = User::where('email', 'admin@example.go.id')->firstOrFail();
        $superAdmin = User::where('email', 'superadmin@example.go.id')->firstOrFail();
        $this->assertNotNull($admin->email_verified_at, 'Bootstrapped admin must have email_verified_at set');
        $this->assertNotNull($superAdmin->email_verified_at, 'Bootstrapped super admin must have email_verified_at set');

        $this->assertDatabaseHas('admin_account_audits', [
            'action' => AdminAccountAudit::ACTION_CREATED,
        ]);
    }

    public function test_command_is_idempotent_for_existing_users(): void
    {
        $this->setBootstrapEnv(
            'admin@example.go.id',
            'temp-pass-1234',
            'superadmin@example.go.id',
            'super-temp-1234',
        );

        Artisan::call('admin:bootstrap-accounts');
        $countBefore = User::count();

        Artisan::call('admin:bootstrap-accounts');
        $this->assertEquals($countBefore, User::count());
    }

    public function test_command_force_reset_password_updates_password(): void
    {
        $this->setBootstrapEnv(
            'admin@example.go.id',
            'initial-pass-1234',
            'superadmin@example.go.id',
            'initial-super-1234',
        );

        Artisan::call('admin:bootstrap-accounts');

        $admin = User::where('email', 'admin@example.go.id')->first();
        $admin->forceFill(['password' => Hash::make('manually-changed')])->save();

        // Re-run with force reset and a new password
        $this->setBootstrapEnv(
            'admin@example.go.id',
            'new-reset-pass-1234',
            'superadmin@example.go.id',
            'initial-super-1234',
        );
        Artisan::call('admin:bootstrap-accounts', ['--force-reset-password' => true]);

        $admin->refresh();
        $this->assertTrue(Hash::check('new-reset-pass-1234', $admin->password));
        $this->assertTrue((bool) $admin->force_password_change);
    }
}
