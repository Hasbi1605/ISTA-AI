<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_force_password_change_is_redirected_from_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.password.change'));
    }

    public function test_admin_with_force_password_change_is_redirected_from_accounts(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get('/admin/accounts')
            ->assertRedirect(route('admin.password.change'));
    }

    public function test_admin_login_with_force_change_redirects_to_change_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
            'password' => Hash::make('temporary-1234'),
        ]);

        // First login redirects intended to dashboard...
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'temporary-1234',
        ])->assertRedirect(route('admin.dashboard'));

        // ...but the dashboard itself bounces to the change-password page
        $this->get('/admin')->assertRedirect(route('admin.password.change'));
    }

    public function test_admin_can_change_password_and_flag_is_cleared(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
            'password' => Hash::make('old-temp-1234'),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.password.change'))
            ->post('/admin/password/change', [
                'current_password' => 'old-temp-1234',
                'password' => 'BrandNew_PassW0rd!',
                'password_confirmation' => 'BrandNew_PassW0rd!',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $admin->refresh();
        $this->assertFalse((bool) $admin->force_password_change);
        $this->assertTrue(Hash::check('BrandNew_PassW0rd!', $admin->password));

        // After change, dashboard is accessible without redirect.
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
            'password' => Hash::make('correct-temp-1234'),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.password.change'))
            ->post('/admin/password/change', [
                'current_password' => 'wrong-current',
                'password' => 'NewSecret_1234!',
                'password_confirmation' => 'NewSecret_1234!',
            ])
            ->assertRedirect(route('admin.password.change'))
            ->assertSessionHasErrors('current_password');

        $admin->refresh();
        $this->assertTrue((bool) $admin->force_password_change);
        $this->assertTrue(Hash::check('correct-temp-1234', $admin->password));
    }

    public function test_change_password_rejects_same_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
            'password' => Hash::make('same-pass-1234!'),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.password.change'))
            ->post('/admin/password/change', [
                'current_password' => 'same-pass-1234!',
                'password' => 'same-pass-1234!',
                'password_confirmation' => 'same-pass-1234!',
            ])
            ->assertRedirect(route('admin.password.change'))
            ->assertSessionHasErrors('password');

        $admin->refresh();
        $this->assertTrue((bool) $admin->force_password_change);
    }

    public function test_profile_password_update_clears_force_change_flag(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
            'password' => Hash::make('temp-1234'),
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test('profile.update-password-form')
            ->set('current_password', 'temp-1234')
            ->set('password', 'NewSecret_1234!')
            ->set('password_confirmation', 'NewSecret_1234!')
            ->call('updatePassword');

        $admin->refresh();
        $this->assertFalse((bool) $admin->force_password_change);
        $this->assertTrue(Hash::check('NewSecret_1234!', $admin->password));
    }

    public function test_admin_with_force_change_can_still_logout(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'force_password_change' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/logout')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }
}
