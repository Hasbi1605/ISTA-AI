<?php

namespace App\Services\Auth;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registration OTP workflow for users that do not exist yet.
 *
 * This intentionally stays separate from account email verification OTP, which
 * belongs to an existing User row and is stored on users.verification_code.
 */
class PendingRegistrationWorkflowService
{
    public function __construct(
        private readonly PendingRegistrationService $pendingRegistrationService,
    ) {
    }

    public function startRegistration(string $name, string $email, string $password, ?string $ipAddress = null): string
    {
        $normalizedEmail = Str::lower($email);

        $existingUser = User::where('email', $normalizedEmail)->first();
        if ($existingUser && is_null($existingUser->email_verified_at)) {
            $existingUser->delete();
        }

        $existingPendingToken = $this->pendingRegistrationService->pendingTokenByEmail($normalizedEmail);
        if ($existingPendingToken) {
            $this->pendingRegistrationService->clearPendingRegistration($existingPendingToken, $normalizedEmail, $ipAddress);
        }

        [$pendingToken, $plainCode] = $this->pendingRegistrationService->createPendingRegistration(
            name: $name,
            email: $normalizedEmail,
            hashedPassword: Hash::make($password),
        );

        Mail::to($normalizedEmail)->send(new VerificationCodeMail($plainCode));

        return $pendingToken;
    }

    public function resendOtp(?string $pendingToken, ?string $ipAddress = null): void
    {
        if (! $pendingToken) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Sesi pendaftaran tidak ditemukan. Silakan daftar ulang.',
            ]);
        }

        $pending = $this->pendingRegistrationService->getPendingRegistration($pendingToken);

        if (! is_array($pending)) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.',
            ]);
        }

        $otpResendRateLimitKey = $this->pendingRegistrationService->otpResendRateLimitKey(
            token: $pendingToken,
            ipAddress: (string) ($ipAddress ?? request()->ip()),
        );

        if (RateLimiter::tooManyAttempts($otpResendRateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($otpResendRateLimitKey);
            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode OTP sudah dikirim ulang. Coba lagi dalam '.$seconds.' detik.',
            ]);
        }

        $plainCode = sprintf('%06d', random_int(0, 999999));
        $email = (string) ($pending['email'] ?? '');

        $this->pendingRegistrationService->storePendingRegistration($pendingToken, [
            'name' => (string) ($pending['name'] ?? ''),
            'email' => $email,
            'password' => (string) ($pending['password'] ?? ''),
            'code_hash' => hash('sha256', $plainCode),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($plainCode));

        RateLimiter::hit($otpResendRateLimitKey, $this->pendingRegistrationService->otpResendCooldownSeconds());
    }

    public function cancelRegistration(?string $pendingToken = null, ?string $ipAddress = null): void
    {
        if (! $pendingToken) {
            return;
        }

        $pending = $this->pendingRegistrationService->getPendingRegistration($pendingToken);
        $pendingEmail = is_array($pending) ? ($pending['email'] ?? null) : null;

        $this->pendingRegistrationService->clearPendingRegistration($pendingToken, $pendingEmail, $ipAddress);
    }

    public function verifyOtp(string $pendingToken, string $verificationCode, ?string $ipAddress = null): User
    {
        $pending = $this->pendingRegistrationService->getPendingRegistration($pendingToken);

        if (! is_array($pending)) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.',
            ]);
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt < now()->getTimestamp()) {
            $this->pendingRegistrationService->clearPendingRegistration(
                $pendingToken,
                $pending['email'] ?? null,
                $ipAddress,
            );

            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode verifikasi sudah kedaluwarsa. Silakan daftar ulang.',
            ]);
        }

        $otpRateLimitKey = $this->pendingRegistrationService->otpRateLimitKey(
            token: $pendingToken,
            ipAddress: (string) ($ipAddress ?? request()->ip()),
        );

        if (RateLimiter::tooManyAttempts($otpRateLimitKey, $this->pendingRegistrationService->otpMaxAttempts())) {
            $seconds = RateLimiter::availableIn($otpRateLimitKey);
            throw ValidationException::withMessages([
                'verification_code_input' => 'Terlalu banyak percobaan OTP. Coba lagi dalam '.ceil($seconds / 60).' menit.',
            ]);
        }

        $providedCodeHash = hash('sha256', $verificationCode);

        if (! hash_equals((string) ($pending['code_hash'] ?? ''), $providedCodeHash)) {
            RateLimiter::hit($otpRateLimitKey, $this->pendingRegistrationService->otpDecaySeconds());

            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode verifikasi tidak valid.',
            ]);
        }

        $email = (string) ($pending['email'] ?? '');
        $lockKey = 'otp_register:'.md5($email);
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'otp' => 'Permintaan sedang diproses. Coba lagi sesaat.',
            ]);
        }

        try {
            $user = DB::transaction(function () use ($email, $pending) {
                $legacyUnverifiedUser = User::where('email', $email)
                    ->whereNull('email_verified_at')
                    ->first();

                if ($legacyUnverifiedUser) {
                    $legacyUnverifiedUser->delete();
                }

                $user = User::create([
                    'name' => (string) ($pending['name'] ?? ''),
                    'email' => $email,
                    'password' => (string) ($pending['password'] ?? ''),
                    'verification_code' => null,
                    'verification_code_expires_at' => null,
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || (($e->errorInfo[1] ?? null) == 1062)) {
                $existingUser = User::where('email', $email)
                    ->whereNotNull('email_verified_at')
                    ->first();

                if ($existingUser) {
                    $user = $existingUser;
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        } finally {
            $lock->release();
        }

        RateLimiter::clear($otpRateLimitKey);

        $this->pendingRegistrationService->clearPendingRegistration($pendingToken, $email, $ipAddress);

        return $user;
    }
}
