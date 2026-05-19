<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_renders_design_system_components(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);

        // Sidebar branding & nav.
        $response->assertSee('admin-sidebar', false);
        $response->assertSee('admin-nav-link', false);
        $response->assertSee('admin-sidebar-profile-menu', false);
        $response->assertSee('Overview', false);

        // Topbar.
        $response->assertSee('admin-topbar', false);

        // KPI cards & sections.
        $response->assertSee('admin-kpi', false);
        $response->assertSee('admin-section', false);

        // Table & empty state.
        $response->assertSee('admin-table', false);
        $response->assertSee('admin-empty-state', false);

        // Loading skeleton.
        $response->assertSee('admin-loading', false);

        // Filter component.
        $response->assertSee('admin-filter', false);
    }

    public function test_super_admin_sees_ai_config_link_in_admin_sidebar(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('AI Configuration', false);
        $response->assertSee('Hanya super admin', false);
    }

    public function test_admin_does_not_see_ai_config_link_in_admin_sidebar(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        $response->assertDontSee('AI Configuration', false);
    }

    public function test_dashboard_nav_profile_shows_admin_link_only_for_admin_roles(): void
    {
        $regular = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($regular)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Dashboard Admin', false);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard Admin', false);

        $this->actingAs($superAdmin)
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard Admin', false);
    }

    public function test_admin_layout_includes_dark_mode_and_ista_branding(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee("'theme'", false);
        $response->assertSee("'dark'", false);
        $response->assertSee('ISTA', false);
        $response->assertSee('AI', false);
        $response->assertSee('ista-shell', false);
    }

    public function test_admin_layout_provides_link_to_enter_chat(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee(route('chat'), false);
        $response->assertSee('Masuk ke Chat', false);
        $response->assertDontSee('Kembali ke Chat', false);
        $response->assertDontSee('Logout', false);
    }

    public function test_ai_configuration_renders_consistent_admin_surface(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin/ai-config');

        $response->assertStatus(200);
        $response->assertSee('admin-ai-config-page', false);
        $response->assertSee('admin-ai-config-kpi-card', false);
        $response->assertSee('admin-ai-config-kpi-card__icon', false);
        $response->assertSee('Parameter Runtime', false);
        $response->assertSee('Service Endpoints', false);
    }
}
