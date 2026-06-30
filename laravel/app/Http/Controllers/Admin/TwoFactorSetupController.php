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

class TwoFactorSetupController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AdminAccountAuditService $audit,
    ) {}

    /**
     * Show the enrollment QR (or the freshly generated recovery codes after
     * a successful confirmation).
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Recovery codes are flashed once right after a successful confirm.
        $recoveryCodes = $request->session()->get('two_factor_recovery_codes');
        if (is_array($recoveryCodes) && $recoveryCodes !== []) {
            return view('admin.auth.two-factor-recovery', [
                'recoveryCodes' => $recoveryCodes,
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            // Already enrolled: either verify this session or proceed.
            if ($this->twoFactor->isSessionVerified($user, $request)) {
                return redirect()->intended(route('admin.dashboard'));
            }

            return redirect()->route('admin.2fa.challenge');
        }

        // Generate (or regenerate) an unconfirmed secret for this enrollment.
        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => encrypt($secret)])->save();

        $qrUri = $this->twoFactor->getQrCodeUri($user, $secret);

        return view('admin.auth.two-factor-setup', [
            'secret' => $secret,
            'qrSvg' => $this->twoFactor->getQrCodeSvg($qrUri),
        ]);
    }

    /**
     * Confirm enrollment with a valid TOTP code, then issue recovery codes.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.2fa.challenge');
        }

        $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:7'],
        ]);

        if (! $user->two_factor_secret) {
            return redirect()->route('admin.2fa.setup')
                ->withErrors(['code' => 'Sesi setup 2FA tidak ditemukan. Mulai ulang dari awal.']);
        }

        $secret = decrypt($user->two_factor_secret);

        if (! $this->twoFactor->verify($secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'Kode OTP tidak valid. Pastikan waktu perangkat Anda sinkron.',
            ]);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($this->twoFactor->hashRecoveryCodes($recoveryCodes))),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->twoFactor->markSessionVerified($user, $request);

        $this->audit->record(
            AdminAccountAudit::ACTION_TWO_FACTOR_ENABLED,
            actor: $user,
            target: $user,
            request: $request,
        );

        return redirect()->route('admin.2fa.setup')
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }
}
