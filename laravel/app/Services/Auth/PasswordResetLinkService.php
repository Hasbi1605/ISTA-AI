<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkService
{
    public function sendResetLink(string $email, string $errorField = 'email'): string
    {
        $user = User::where('email', $email)->first();

        if ($user && is_null($user->email_verified_at)) {
            throw ValidationException::withMessages([
                $errorField => 'Email belum terverifikasi. Verifikasi akun dengan OTP terbaru sebelum meminta reset kata sandi.',
            ]);
        }

        $status = Password::sendResetLink([
            'email' => $email,
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                $errorField => __($status),
            ]);
        }

        return __($status);
    }
}
