<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_two_factor_is_forced_into_setup(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.2fa.setup'));
    }

    public function test_setup_page_renders_qr_for_unenrolled_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.2fa.setup'));

        $response->assertOk();
        $response->assertSee('Aktifkan Verifikasi 2 Langkah');
        $response->assertSee('<svg', false);

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    public function test_admin_can_confirm_setup_and_receive_recovery_codes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Trigger setup so a secret is generated.
        $this->actingAs($admin)->get(route('admin.2fa.setup'))->assertOk();
        $admin->refresh();
        $secret = decrypt($admin->two_factor_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);

        $response = $this->actingAs($admin)->post(route('admin.2fa.confirm'), [
            'code' => $code,
        ]);

        $response->assertRedirect(route('admin.2fa.setup'));
        $response->assertSessionHas('two_factor_recovery_codes');

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertNotNull($admin->two_factor_recovery_codes);

        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_TWO_FACTOR_ENABLED,
        ]);
    }

    public function test_confirm_rejects_invalid_code(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.2fa.setup'))->assertOk();

        $this->actingAs($admin)
            ->from(route('admin.2fa.setup'))
            ->post(route('admin.2fa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $admin->refresh();
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    public function test_enrolled_admin_is_redirected_to_challenge_when_session_unverified(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_admin_passes_challenge_with_valid_totp(): void
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($admin)
            ->withSession(['admin_session_started_at' => now()->timestamp])
            ->post(route('admin.2fa.verify'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_TWO_FACTOR_VERIFIED,
        ]);
    }

    public function test_challenge_rejects_invalid_code_and_audits_failure(): void
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.2fa.challenge'))
            ->withSession(['admin_session_started_at' => now()->timestamp])
            ->post(route('admin.2fa.verify'), ['code' => '111111'])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseHas('admin_account_audits', [
            'target_user_id' => $admin->id,
            'action' => AdminAccountAudit::ACTION_TWO_FACTOR_FAILED,
        ]);
    }

    public function test_recovery_code_can_be_used_once(): void
    {
        $service = app(TwoFactorService::class);
        $secret = (new Google2FA)->generateSecretKey(32);
        $plainCodes = $service->generateRecoveryCodes();

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($service->hashRecoveryCodes($plainCodes))),
            'two_factor_confirmed_at' => now(),
        ]);

        $recovery = $plainCodes[0];

        $this->actingAs($admin)
            ->withSession(['admin_session_started_at' => now()->timestamp])
            ->post(route('admin.2fa.verify'), ['code' => $recovery])
            ->assertRedirect(route('admin.dashboard'));

        // The same recovery code must not work a second time.
        $fresh = User::find($admin->id);
        $this->assertFalse($service->useRecoveryCode($fresh, $recovery));
    }

    public function test_trusted_device_skips_challenge_on_next_request(): void
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($admin)
            ->withSession(['admin_session_started_at' => now()->timestamp])
            ->post(route('admin.2fa.verify'), [
                'code' => $code,
                'trust_device' => '1',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('trusted_devices', [
            'user_id' => $admin->id,
        ]);
    }

    public function test_verified_admin_can_reach_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAsVerifiedAdmin($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_session_expires_after_absolute_lifetime(): void
    {
        config(['session.admin_absolute_lifetime' => 60]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession([
                'admin_session_started_at' => now()->subMinutes(61)->timestamp,
                TwoFactorService::VERIFIED_USER_ID_SESSION_KEY => $admin->id,
            ])
            ->get('/admin')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_admin_within_absolute_lifetime_is_not_logged_out(): void
    {
        config(['session.admin_absolute_lifetime' => 720]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession([
                'admin_session_started_at' => now()->subMinutes(30)->timestamp,
                TwoFactorService::VERIFIED_USER_ID_SESSION_KEY => $admin->id,
            ])
            ->get('/admin')
            ->assertOk();
    }
}
