<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_promote_admin_command_sets_role_to_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'role' => User::ROLE_USER,
        ]);

        $this->artisan('users:promote-admin', ['email' => $user->email])
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame(User::ROLE_ADMIN, $user->role);
    }

    public function test_promote_admin_command_fails_when_user_missing(): void
    {
        $this->artisan('users:promote-admin', ['email' => 'unknown@example.com'])
            ->assertExitCode(1);
    }

    public function test_promote_admin_command_does_not_demote_super_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'super@example.com',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->artisan('users:promote-admin', ['email' => $user->email])
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
    }

    public function test_promote_super_admin_command_sets_role_to_super_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'sa@example.com',
            'role' => User::ROLE_USER,
        ]);

        $this->artisan('users:promote-super-admin', ['email' => $user->email])
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
    }

    public function test_promote_super_admin_command_fails_when_user_missing(): void
    {
        $this->artisan('users:promote-super-admin', ['email' => 'unknown@example.com'])
            ->assertExitCode(1);
    }
}
