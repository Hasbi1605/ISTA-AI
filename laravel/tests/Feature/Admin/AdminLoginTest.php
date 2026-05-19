<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_renders_without_public_links(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('Login Admin');
        $response->assertDontSee('Admin Console');
        $response->assertSee('data-admin-theme-toggle', false);
        $response->assertDontSee('Daftar', false);
        $response->assertDontSee('Lupa Password', false);
        $response->assertDontSee('Guest Chat', false);
        $response->assertDontSee(route('register'), false);
        $response->assertDontSee(route('password.request'), false);
        $response->assertDontSee(route('guest-chat'), false);
    }

    public function test_active_admin_can_login_via_admin_login(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => Hash::make('correct-pass-1234'),
        ]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-pass-1234',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_LOGIN_SUCCESS,
        ]);
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
            'password' => Hash::make('correct-pass-1234'),
        ]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-pass-1234',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_LOGIN_FAILED,
        ]);
    }

    public function test_regular_user_cannot_login_via_admin_login(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'password' => Hash::make('correct-pass-1234'),
        ]);

        $response = $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'correct-pass-1234',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $user->id,
            'action' => AdminAccountAudit::ACTION_LOGIN_FAILED,
        ]);
    }

    public function test_login_with_wrong_password_is_audited_and_returns_generic_error(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => Hash::make('correct-pass-1234'),
        ]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-pass',
        ]);

        $response->assertSessionHasErrors('email');
        $errors = session('errors')->getBag('default')->first('email');
        $this->assertStringContainsString('Email atau password salah', $errors);
        $this->assertGuest();
    }

    public function test_login_with_unknown_email_does_not_disclose_existence(): void
    {
        $response = $this->post('/admin/login', [
            'email' => 'no-such-user@example.com',
            'password' => 'whatever-1234',
        ]);

        $response->assertSessionHasErrors('email');
        $errors = session('errors')->getBag('default')->first('email');
        $this->assertStringContainsString('Email atau password salah', $errors);
        $this->assertDatabaseHas('admin_account_audits', [
            'action' => AdminAccountAudit::ACTION_LOGIN_FAILED,
        ]);
    }

    public function test_admin_logout_clears_session_and_audits(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/logout')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_LOGOUT,
        ]);
    }

    public function test_admin_login_records_last_admin_login_metadata(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => Hash::make('correct-pass-1234'),
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-pass-1234',
        ]);

        $admin->refresh();
        $this->assertNotNull($admin->last_admin_login_at);
        $this->assertNotNull($admin->last_admin_login_ip);
    }

    public function test_inactive_admin_with_session_cannot_access_admin(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
