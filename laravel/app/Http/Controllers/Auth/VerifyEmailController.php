<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        abort_if(!hash_equals((string) $request->route('id'), (string) $request->user()->getKey()), 403);
        abort_if(!hash_equals((string) $request->route('hash'), sha1($request->user()->getEmailForVerification())), 403);

        $user = $request->user();

        if ($request->has('verification_code')) {
            $hashedCode = hash('sha256', $request->verification_code);
            abort_if(!hash_equals((string) $user->verification_code, $hashedCode), 403, 'Invalid verification code.');
            abort_if($user->verification_code_expires_at && now()->greaterThan($user->verification_code_expires_at), 403, 'Verification code expired.');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false) . '?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));

            $user->forceFill([
                'verification_code' => null,
                'verification_code_expires_at' => null,
            ])->save();
        }

        return redirect()->intended(route('dashboard', absolute: false) . '?verified=1');
    }
}
