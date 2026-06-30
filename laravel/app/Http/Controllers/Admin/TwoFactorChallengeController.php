<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccountAudit;
use App\Services\Admin\AdminAccountAuditService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AdminAccountAuditService $audit,
    ) {}

    /**
     * Show the 2FA challenge for an admin whose session is not yet verified.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.2fa.setup');
        }

        if ($this->twoFactor->isSessionVerified($user, $request)) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return view('admin.auth.two-factor-challenge');
    }

    /**
     * Verify a TOTP or recovery code and mark the session as 2FA-verified.
     */
    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.2fa.setup');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);

        $code = (string) $request->input('code');
        $secret = decrypt($user->two_factor_secret);

        $valid = $this->twoFactor->verify($secret, $code)
            || $this->twoFactor->useRecoveryCode($user, $code);

        if (! $valid) {
            $this->audit->record(
                AdminAccountAudit::ACTION_TWO_FACTOR_FAILED,
                actor: $user,
                target: $user,
                request: $request,
            );

            throw ValidationException::withMessages([
                'code' => 'Kode verifikasi tidak valid.',
            ]);
        }

        $this->twoFactor->markSessionVerified($user, $request);

        $this->audit->record(
            AdminAccountAudit::ACTION_TWO_FACTOR_VERIFIED,
            actor: $user,
            target: $user,
            request: $request,
        );

        $response = redirect()->intended(route('admin.dashboard'));

        if ($request->boolean('trust_device')) {
            $response->withCookie($this->twoFactor->trustDevice($user, $request, 30));
        }

        return $response;
    }
}
