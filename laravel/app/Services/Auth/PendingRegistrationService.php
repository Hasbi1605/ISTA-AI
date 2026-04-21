<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PendingRegistrationService
{
    public function pendingRegistrationKey(string $token): string
    {
        return 'pending_registration:'.$token;
    }

    public function pendingRegistrationEmailKey(string $email): string
    {
        return 'pending_registration_email:'.Str::lower($email);
    }

    public function otpRateLimitKey(string $token, string $ipAddress): string
    {
        return 'otp_registration:'.$token.'|'.$ipAddress;
    }

    public function otpResendRateLimitKey(string $token, string $ipAddress): string
    {
        return 'otp_registration_resend:'.$token.'|'.$ipAddress;
    }

    public function pendingRegistrationTtlMinutes(): int
    {
        return max(1, (int) config('auth.otp_registration.ttl_minutes', 60));
    }

    public function otpMaxAttempts(): int
    {
        return max(1, (int) config('auth.otp_registration.max_attempts', 3));
    }

    public function otpDecaySeconds(): int
    {
        return max(1, (int) config('auth.otp_registration.decay_seconds', 600));
    }

    public function otpResendCooldownSeconds(): int
    {
        return max(1, (int) config('auth.otp_registration.resend_cooldown_seconds', 60));
    }

    public function pendingTokenByEmail(string $email): ?string
    {
        $token = Cache::get($this->pendingRegistrationEmailKey($email));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function getPendingRegistration(string $token): ?array
    {
        $pending = Cache::get($this->pendingRegistrationKey($token));

        return is_array($pending) ? $pending : null;
    }

    public function createPendingRegistration(string $name, string $email, string $hashedPassword): array
    {
        $plainCode = sprintf('%06d', random_int(0, 999999));
        $pendingToken = (string) Str::uuid();

        $this->storePendingRegistration($pendingToken, [
            'name' => $name,
            'email' => Str::lower($email),
            'password' => $hashedPassword,
            'code_hash' => hash('sha256', $plainCode),
        ]);

        return [$pendingToken, $plainCode];
    }

    public function storePendingRegistration(string $token, array $payload): void
    {
        $ttlMinutes = $this->pendingRegistrationTtlMinutes();
        $email = Str::lower((string) ($payload['email'] ?? ''));

        Cache::put($this->pendingRegistrationKey($token), [
            'name' => (string) ($payload['name'] ?? ''),
            'email' => $email,
            'password' => (string) ($payload['password'] ?? ''),
            'code_hash' => (string) ($payload['code_hash'] ?? ''),
            'expires_at' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ], now()->addMinutes($ttlMinutes));

        Cache::put(
            $this->pendingRegistrationEmailKey($email),
            $token,
            now()->addMinutes($ttlMinutes)
        );
    }

    public function clearPendingRegistration(?string $token = null, ?string $email = null, ?string $ipAddress = null): void
    {
        if ($token) {
            Cache::forget($this->pendingRegistrationKey($token));

            if ($ipAddress) {
                RateLimiter::clear($this->otpRateLimitKey($token, $ipAddress));
                RateLimiter::clear($this->otpResendRateLimitKey($token, $ipAddress));
            }
        }

        if ($email) {
            Cache::forget($this->pendingRegistrationEmailKey($email));
        }
    }
}
