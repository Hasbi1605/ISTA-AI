<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_horizon(): void
    {
        $this->get('/horizon')
            ->assertRedirect(route('admin.login'));
    }

    public function test_regular_admin_cannot_access_horizon(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/horizon')
            ->assertForbidden();
    }

    public function test_super_admin_can_access_horizon(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'force_password_change' => false,
        ]);

        $this->actingAs($superAdmin)
            ->get('/horizon')
            ->assertOk()
            ->assertSee('Horizon', false);
    }

    public function test_super_admin_forced_to_change_password_cannot_access_horizon(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get('/horizon')
            ->assertRedirect(route('admin.password.change'));
    }
}
