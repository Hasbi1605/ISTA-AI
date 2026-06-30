<?php

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie;

class TwoFactorService
{
    public const TRUSTED_DEVICE_COOKIE = 'ista_trusted_device';

    public const VERIFIED_USER_ID_SESSION_KEY = 'two_factor_verified_user_id';

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /**
     * Generate a new 2FA secret for the user.
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * Get the provisioning URI used to build the authenticator QR code.
     */
    public function getQrCodeUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name', 'ISTA AI'),
            $user->email,
            $secret,
        );
    }

    /**
     * Verify a TOTP code against the user's secret.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $this->normalizeTotpCode($code));
    }

    /**
     * Generate plaintext recovery codes (shown to the user once).
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    /**
     * Hash recovery codes for storage.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($this->normalizeRecoveryCode($code)), $codes);
    }

    /**
     * Consume a recovery code if it matches one of the user's stored codes.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        if (! is_array($codes)) {
            return false;
        }

        $normalizedCode = $this->normalizeRecoveryCode($code);

        foreach ($codes as $index => $storedCode) {
            if (! is_string($storedCode) || ! $this->recoveryCodeMatches($normalizedCode, $storedCode)) {
                continue;
            }

            unset($codes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode($this->rehashPlainRecoveryCodes(array_values($codes)))),
            ])->save();

            return true;
        }

        return false;
    }

    /**
     * Number of unused recovery codes remaining for the user.
     */
    public function countRemainingRecoveryCodes(User $user): int
    {
        if (! $user->two_factor_recovery_codes) {
            return 0;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * Check if a device is trusted for the user via signed cookie + stored hash.
     */
    public function isDeviceTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie(self::TRUSTED_DEVICE_COOKIE);
        if (! is_string($token) || ! $this->isValidTrustedDeviceToken($token)) {
            return false;
        }

        return TrustedDevice::where('user_id', $user->id)
            ->where('device_hash', $this->hashTrustedDeviceToken($token))
            ->where('trusted_until', '>', now())
            ->exists();
    }

    public function isSessionVerified(User $user, Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $verifiedUserId = $request->session()->get(self::VERIFIED_USER_ID_SESSION_KEY);

        return $verifiedUserId !== null
            && (string) $verifiedUserId === (string) $user->getAuthIdentifier();
    }

    public function markSessionVerified(User $user, Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::VERIFIED_USER_ID_SESSION_KEY, $user->getAuthIdentifier());
    }

    public function clearSessionVerification(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(self::VERIFIED_USER_ID_SESSION_KEY);
    }

    /**
     * Trust the current device for N days, returning the cookie to attach.
     */
    public function trustDevice(User $user, Request $request, int $days = 30): Cookie
    {
        $token = Str::random(64);
        $deviceHash = $this->hashTrustedDeviceToken($token);
        $deviceName = $this->getDeviceName($request);

        TrustedDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_hash' => $deviceHash],
            ['device_name' => $deviceName, 'trusted_until' => now()->addDays($days)],
        );

        TrustedDevice::where('user_id', $user->id)
            ->where('trusted_until', '<', now())
            ->delete();

        return cookie(
            self::TRUSTED_DEVICE_COOKIE,
            $token,
            $days * 24 * 60,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }

    /**
     * Revoke all trusted devices for the user.
     */
    public function revokeAllDevices(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    public function forgetTrustedDeviceCookie(): Cookie
    {
        return cookie()->forget(self::TRUSTED_DEVICE_COOKIE, '/', config('session.domain'));
    }

    public function hashTrustedDeviceToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Build a QR code SVG string for the provisioning URI.
     */
    public function getQrCodeSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($uri);
    }

    private function isValidTrustedDeviceToken(string $token): bool
    {
        return strlen($token) >= 64 && preg_match('/^[A-Za-z0-9]+$/', $token) === 1;
    }

    private function normalizeTotpCode(string $code): string
    {
        return preg_replace('/\s+/', '', trim($code)) ?? '';
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    private function recoveryCodeMatches(string $code, string $storedCode): bool
    {
        if (str_starts_with($storedCode, '$2y$') || str_starts_with($storedCode, '$argon2')) {
            return Hash::check($code, $storedCode);
        }

        return hash_equals($this->normalizeRecoveryCode($storedCode), $code);
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    private function rehashPlainRecoveryCodes(array $codes): array
    {
        return array_values(array_filter(array_map(function (mixed $storedCode): ?string {
            if (! is_string($storedCode)) {
                return null;
            }

            if (str_starts_with($storedCode, '$2y$') || str_starts_with($storedCode, '$argon2')) {
                return $storedCode;
            }

            return Hash::make($this->normalizeRecoveryCode($storedCode));
        }, $codes)));
    }

    /**
     * Best-effort device name extracted from the user agent.
     */
    private function getDeviceName(Request $request): string
    {
        $ua = $request->userAgent() ?? 'Unknown';

        foreach (['Edge', 'Chrome', 'Firefox', 'Safari'] as $browser) {
            if (str_contains($ua, $browser)) {
                return $browser;
            }
        }

        return Str::limit($ua, 50);
    }
}
