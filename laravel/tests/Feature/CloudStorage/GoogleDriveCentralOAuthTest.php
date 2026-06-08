<?php

namespace Tests\Feature\CloudStorage;

use App\Models\GoogleDriveOAuthConnection;
use App\Models\User;
use App\Services\CloudStorage\GoogleDriveOAuthService;
use App\Services\CloudStorage\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GoogleDriveCentralOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_oauth_connection_enables_google_drive_uploads_for_the_app(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.root_folder_id', 'root-folder-id');

        $user = User::factory()->create();

        $connection = app(GoogleDriveOAuthService::class)->storeTokenPayload([
            'refresh_token' => 'central-refresh-token',
            'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/drive',
        ], $user);

        $this->assertSame(GoogleDriveOAuthConnection::PROVIDER, $connection->provider);
        $this->assertSame('central-refresh-token', $connection->refresh_token);
        $this->assertSame($user->id, $connection->connected_by_user_id);
        $this->assertTrue(app(GoogleDriveService::class)->isConfigured());
        $this->assertTrue(app(GoogleDriveService::class)->canUploadWithConfiguredAccount());

        $rawRefreshToken = DB::table('google_drive_oauth_connections')->value('refresh_token');

        $this->assertIsString($rawRefreshToken);
        $this->assertNotSame('central-refresh-token', $rawRefreshToken);
    }

    public function test_central_oauth_connection_requires_refresh_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google tidak mengirim refresh token');

        app(GoogleDriveOAuthService::class)->storeTokenPayload([
            'access_token' => 'temporary-access-token',
        ]);
    }

    public function test_google_drive_connect_route_requires_setup_key_when_configured(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'wrong-secret']))
            ->assertForbidden();

        $response = $this->actingAs($user)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }

    public function test_google_drive_callback_handles_invalid_state_without_exchanging_token(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('chat.google-drive.oauth.callback', [
                'code' => 'temporary-code',
                'state' => 'invalid-state',
            ]))
            ->assertRedirect(route('chat'))
            ->assertSessionHas('error');
    }

    // -------------------------------------------------------------------------
    // Admin gate: only active Laravel admins may connect central OAuth
    // -------------------------------------------------------------------------

    public function test_connect_route_rejects_non_admin_even_with_valid_setup_key(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');
        // Only admin@ista.test is allowed to perform the setup.
        config()->set('services.google_drive.oauth_admin_emails', 'admin@ista.test');

        // Regular user with a different email provides the correct setup key.
        $regularUser = User::factory()->create(['email' => 'regular@ista.test']);

        $this->actingAs($regularUser)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']))
            ->assertForbidden();
    }

    public function test_connect_route_rejects_allowlisted_email_without_admin_role(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');
        config()->set('services.google_drive.oauth_admin_emails', 'admin@ista.test');

        $regularUser = User::factory()->create([
            'email' => 'admin@ista.test',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($regularUser)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']))
            ->assertForbidden();
    }

    public function test_connect_route_rejects_inactive_allowlisted_admin(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');
        config()->set('services.google_drive.oauth_admin_emails', 'admin@ista.test');

        $inactiveAdmin = User::factory()->create([
            'email' => 'admin@ista.test',
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $this->actingAs($inactiveAdmin)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']))
            ->assertForbidden();
    }

    public function test_connect_route_allows_admin_user_with_valid_setup_key(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');
        config()->set('services.google_drive.oauth_admin_emails', 'admin@ista.test');

        $adminUser = User::factory()->create([
            'email' => 'admin@ista.test',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($adminUser)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }

    public function test_callback_route_rejects_non_admin_user(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_admin_emails', 'admin@ista.test');

        $regularUser = User::factory()->create(['email' => 'regular@ista.test']);

        $this->actingAs($regularUser)
            ->get(route('chat.google-drive.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'some-state',
            ]))
            ->assertForbidden();
    }

    public function test_admin_allowlist_is_case_insensitive(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', 'setup-secret');
        // Config uses uppercase; user email is lowercase.
        config()->set('services.google_drive.oauth_admin_emails', 'Admin@ISTA.test');

        $adminUser = User::factory()->create([
            'email' => 'admin@ista.test',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($adminUser)
            ->get(route('chat.google-drive.oauth.connect', ['setup_key' => 'setup-secret']));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }

    public function test_local_fallback_without_email_allowlist_still_requires_active_admin(): void
    {
        config()->set('services.google_drive.oauth_client_id', 'oauth-client-id');
        config()->set('services.google_drive.oauth_client_secret', 'oauth-client-secret');
        config()->set('services.google_drive.oauth_setup_key', null);
        config()->set('services.google_drive.oauth_admin_emails', null);

        $regularUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $activeAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($regularUser)
            ->get(route('chat.google-drive.oauth.connect'))
            ->assertForbidden();

        $response = $this->actingAs($activeAdmin)
            ->get(route('chat.google-drive.oauth.connect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }
}
