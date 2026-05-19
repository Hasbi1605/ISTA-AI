<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccountAudit;
use App\Models\User;
use App\Services\Admin\AdminAccountAuditService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    /**
     * Generic message returned for any login failure to avoid account enumeration.
     */
    private const GENERIC_FAILURE_MESSAGE = 'Email atau password salah, atau akun tidak dapat mengakses admin.';

    public function __construct(private readonly AdminAccountAuditService $audit)
    {
    }

    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if ($request->user() && $request->user()->canAccessAdmin()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request, $validated['email']);

        $email = strtolower(trim($validated['email']));
        $user = User::query()->where('email', $email)->first();

        $reason = null;

        if (! $user || ! Hash::check($validated['password'], (string) $user->password)) {
            $reason = 'invalid_credentials';
        } elseif (! $user->isAdminFamily()) {
            $reason = 'not_admin';
        } elseif (! $user->isActive()) {
            $reason = 'account_disabled';
        } elseif (is_null($user->email_verified_at)) {
            $reason = 'unverified';
        }

        if ($reason !== null) {
            RateLimiter::hit($this->throttleKey($request, $email));

            $this->audit->record(
                AdminAccountAudit::ACTION_LOGIN_FAILED,
                actor: null,
                target: $user,
                metadata: [
                    'reason' => $reason,
                    'email_attempt_hash' => hash('sha256', $email),
                ],
                request: $request,
            );

            throw ValidationException::withMessages([
                'email' => self::GENERIC_FAILURE_MESSAGE,
            ]);
        }

        Auth::login($user, false);
        $request->session()->regenerate();

        $user->forceFill([
            'last_admin_login_at' => now(),
            'last_admin_login_ip' => $request->ip(),
        ])->save();

        RateLimiter::clear($this->throttleKey($request, $email));

        $this->audit->record(
            AdminAccountAudit::ACTION_LOGIN_SUCCESS,
            actor: $user,
            target: $user,
            metadata: ['role' => $user->role],
            request: $request,
        );

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->record(
                AdminAccountAudit::ACTION_LOGOUT,
                actor: $user,
                target: $user,
                request: $request,
            );
        }

        return redirect()->route('admin.login');
    }

    private function ensureIsNotRateLimited(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]),
        ]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'admin-login|'.Str::transliterate(Str::lower($email)).'|'.$request->ip();
    }
}
