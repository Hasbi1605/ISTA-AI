<?php

namespace App\Services\CloudStorage;

use App\Models\GoogleDriveOAuthConnection;
use App\Models\User;
use Google\Client;
use Google\Service\Drive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveOAuthService
{
    private const GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';

    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    public function hasCentralConnection(): bool
    {
        $connection = GoogleDriveOAuthConnection::central();

        return $this->isConfigured()
            && $connection !== null
            && $this->normalizeNullableString($connection->refresh_token) !== null;
    }

    public function centralConnection(): ?GoogleDriveOAuthConnection
    {
        return GoogleDriveOAuthConnection::central();
    }

    public function canUseSetupKey(?string $providedSetupKey): bool
    {
        $expectedSetupKey = $this->setupKey();

        if ($expectedSetupKey === null) {
            return app()->environment(['local', 'testing']);
        }

        return is_string($providedSetupKey) && hash_equals($expectedSetupKey, $providedSetupKey);
    }

    /**
     * Determine whether the given user is allowed to perform the central
     * Google Drive OAuth setup.
     *
     * The setup user must be an active Laravel admin/super-admin first. The
     * optional email allowlist then narrows which admins may connect the shared
     * app-wide Drive account. Without an email allowlist, the flow is only open
     * to active admins in local/testing and remains fail-closed in production.
     */
    public function isAllowedAdminUser(User $user): bool
    {
        if (! $user->canAccessAdmin()) {
            return false;
        }

        $adminEmails = $this->adminEmails();

        if (! empty($adminEmails)) {
            return in_array(strtolower((string) $user->email), $adminEmails, true);
        }

        // No allowlist configured: allow only in non-production environments so
        // the OAuth flow is fail-closed in production without explicit admin setup.
        return app()->environment(['local', 'testing']);
    }

    public function authorizationUrl(User $user): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Konfigurasi OAuth Google Drive belum lengkap.');
        }

        $client = $this->oauthClient();
        $nonce = Str::random(40);
        Cache::put("gdrive_oauth_nonce:{$user->id}", $nonce, now()->addMinutes(20));
        $client->setState(Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(15)->timestamp,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR)));

        return $client->createAuthUrl();
    }

    public function completeCallback(string $code, string $state, User $user): GoogleDriveOAuthConnection
    {
        $this->validateState($state, $user);

        $client = $this->oauthClient();
        $tokenPayload = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokenPayload['error'])) {
            throw new RuntimeException('OAuth Google Drive gagal: '.($tokenPayload['error_description'] ?? $tokenPayload['error']));
        }

        return $this->storeTokenPayload($tokenPayload, $user, $client);
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     */
    public function storeTokenPayload(array $tokenPayload, ?User $connectedBy = null, ?Client $client = null): GoogleDriveOAuthConnection
    {
        $existingConnection = GoogleDriveOAuthConnection::central();
        $refreshToken = $this->normalizeNullableString($tokenPayload['refresh_token'] ?? null)
            ?? $this->normalizeNullableString($existingConnection?->refresh_token);

        if ($refreshToken === null) {
            throw new RuntimeException('Google tidak mengirim refresh token. Buka ulang halaman connect dan pastikan consent OAuth disetujui.');
        }

        $accessToken = $this->normalizeNullableString($tokenPayload['access_token'] ?? null);
        $client ??= $this->oauthClient();

        if ($accessToken !== null) {
            $client->setAccessToken($this->normalizeTokenPayloadForClient($tokenPayload, $refreshToken));
        }

        $accountEmail = $this->resolveConnectedAccountEmail($client);

        return GoogleDriveOAuthConnection::query()->updateOrCreate(
            ['provider' => GoogleDriveOAuthConnection::PROVIDER],
            [
                'account_email' => $accountEmail ?? $existingConnection?->account_email,
                'access_token' => $accessToken ?? $existingConnection?->access_token,
                'refresh_token' => $refreshToken,
                'token_type' => $this->normalizeNullableString($tokenPayload['token_type'] ?? null) ?? $existingConnection?->token_type ?? 'Bearer',
                'scope' => $this->normalizeNullableString($tokenPayload['scope'] ?? null) ?? $existingConnection?->scope,
                'expires_at' => $this->resolveExpiresAt($tokenPayload) ?? $existingConnection?->expires_at,
                'connected_by_user_id' => $connectedBy?->id ?? $existingConnection?->connected_by_user_id,
            ],
        );
    }

    public function clientForCentralConnection(): Client
    {
        $connection = GoogleDriveOAuthConnection::central();

        if ($connection === null) {
            throw new RuntimeException('Akun Google Drive pusat belum tersambung.');
        }

        $client = $this->oauthClient();
        $client->setAccessToken($this->tokenPayloadForConnection($connection));

        if ($client->isAccessTokenExpired()) {
            $this->refreshAccessToken($client, $connection);
        }

        return $client;
    }

    private function oauthClient(): Client
    {
        $client = new Client;
        $client->setApplicationName(config('app.name', 'ISTA AI'));
        $client->setClientId($this->clientId());
        $client->setClientSecret($this->clientSecret());
        $client->setRedirectUri($this->redirectUri());
        $client->setScopes([self::GOOGLE_DRIVE_SCOPE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    private function refreshAccessToken(Client $client, GoogleDriveOAuthConnection $connection): void
    {
        $tokenPayload = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

        if (isset($tokenPayload['error'])) {
            throw new RuntimeException('Gagal memperbarui token Google Drive: '.($tokenPayload['error_description'] ?? $tokenPayload['error']));
        }

        $refreshToken = $this->normalizeNullableString($tokenPayload['refresh_token'] ?? null) ?? $connection->refresh_token;
        $connection->forceFill([
            'access_token' => $this->normalizeNullableString($tokenPayload['access_token'] ?? null) ?? $connection->access_token,
            'refresh_token' => $refreshToken,
            'token_type' => $this->normalizeNullableString($tokenPayload['token_type'] ?? null) ?? $connection->token_type,
            'scope' => $this->normalizeNullableString($tokenPayload['scope'] ?? null) ?? $connection->scope,
            'expires_at' => $this->resolveExpiresAt($tokenPayload) ?? $connection->expires_at,
            'last_refreshed_at' => now(),
        ])->save();

        $refreshedConnection = $connection->fresh();
        $client->setAccessToken($this->tokenPayloadForConnection($refreshedConnection ?? $connection));
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayloadForConnection(GoogleDriveOAuthConnection $connection): array
    {
        $expiresIn = 3600;
        $created = 0;

        if ($connection->expires_at !== null) {
            $created = max(0, $connection->expires_at->timestamp - $expiresIn);
        }

        return [
            'access_token' => (string) ($connection->access_token ?? ''),
            'refresh_token' => $connection->refresh_token,
            'token_type' => $connection->token_type ?: 'Bearer',
            'scope' => $connection->scope ?: self::GOOGLE_DRIVE_SCOPE,
            'expires_in' => $expiresIn,
            'created' => $created,
        ];
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     * @return array<string, mixed>
     */
    private function normalizeTokenPayloadForClient(array $tokenPayload, string $refreshToken): array
    {
        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 3600);

        return [
            'access_token' => (string) ($tokenPayload['access_token'] ?? ''),
            'refresh_token' => $refreshToken,
            'token_type' => (string) ($tokenPayload['token_type'] ?? 'Bearer'),
            'scope' => (string) ($tokenPayload['scope'] ?? self::GOOGLE_DRIVE_SCOPE),
            'expires_in' => $expiresIn > 0 ? $expiresIn : 3600,
            'created' => time(),
        ];
    }

    private function validateState(string $state, User $user): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('State OAuth Google Drive tidak valid.');
        }

        if (! is_array($payload)) {
            throw new RuntimeException('State OAuth Google Drive tidak valid.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            throw new RuntimeException('State OAuth Google Drive tidak cocok dengan user saat ini.');
        }

        if ((int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw new RuntimeException('State OAuth Google Drive sudah kedaluwarsa. Silakan mulai connect ulang.');
        }

        $expectedNonce = Cache::pull("gdrive_oauth_nonce:{$payload['user_id']}");
        if ($expectedNonce === null || ! hash_equals($expectedNonce, $payload['nonce'] ?? '')) {
            throw new RuntimeException('State OAuth Google Drive tidak valid atau sudah digunakan.');
        }
    }

    private function resolveConnectedAccountEmail(Client $client): ?string
    {
        if ($client->getAccessToken() === null || $client->isAccessTokenExpired()) {
            return null;
        }

        try {
            $about = (new Drive($client))->about->get([
                'fields' => 'user(emailAddress)',
            ]);

            return $this->normalizeNullableString($about->getUser()?->getEmailAddress());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     */
    private function resolveExpiresAt(array $tokenPayload): ?Carbon
    {
        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 0);

        if ($expiresIn < 1) {
            return null;
        }

        return now()->addSeconds($expiresIn);
    }

    private function clientId(): ?string
    {
        return $this->normalizeNullableString(config('services.google_drive.oauth_client_id'));
    }

    private function clientSecret(): ?string
    {
        return $this->normalizeNullableString(config('services.google_drive.oauth_client_secret'));
    }

    private function redirectUri(): string
    {
        return $this->normalizeNullableString(config('services.google_drive.oauth_redirect_uri'))
            ?? route('chat.google-drive.oauth.callback');
    }

    private function setupKey(): ?string
    {
        return $this->normalizeNullableString(config('services.google_drive.oauth_setup_key'));
    }

    /**
     * Return the normalised lowercase list of email addresses that are
     * permitted to perform the central Google Drive OAuth setup.
     *
     * @return list<string>
     */
    private function adminEmails(): array
    {
        $raw = config('services.google_drive.oauth_admin_emails', '');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $e) => strtolower(trim($e)), explode(',', $raw)),
        ));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
