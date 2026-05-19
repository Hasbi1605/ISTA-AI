<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_for_admin_dashboard(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_regular_user_is_redirected_to_admin_login_when_accessing_admin(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Ringkasan Operasional', false);
        $response->assertSee('Dashboard Admin', false);
    }

    public function test_super_admin_can_access_admin_dashboard(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Ringkasan Operasional', false);
    }

    public function test_admin_is_forbidden_from_super_admin_only_routes(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin/ai-config');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_access_ai_configuration(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin/ai-config');

        $response->assertStatus(200);
        $response->assertSee('AI Configuration', false);
        $response->assertSee('Akses terbatas', false);
        $response->assertSee('Model Routing', false);
        $response->assertSee('Prompt Profiles', false);
        $response->assertDontSee('Placeholder', false);
    }

    public function test_unverified_user_is_redirected_for_admin_routes(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect(route('verification.notice'));
    }
}
