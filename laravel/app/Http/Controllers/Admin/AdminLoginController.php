<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccountAudit;
use App\Models\User;
use App\Rules\NoEmailHeaderInjection;
use App\Services\Admin\AdminAccountAuditService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    /**
     * Generic message returned for any login failure to avoid account enumeration.
     */
    private const GENERIC_FAILURE_MESSAGE = 'Email atau password salah, atau akun tidak dapat mengakses admin.';

    /**
     * Failed attempts allowed before a progressive delay kicks in.
     */
    private const MAX_ATTEMPTS_BEFORE_DELAY = 3;

    /**
     * Upper bound for the exponential lockout delay (seconds).
     */
    private const MAX_DELAY_SECONDS = 300;

    public function __construct(private readonly AdminAccountAuditService $audit) {}

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
            'email' => ['required', 'string', 'email', new NoEmailHeaderInjection, 'max:255'],
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
            $this->recordFailedAttempt($request, $email);

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
        $request->session()->put('admin_session_started_at', now()->timestamp);

        $user->forceFill([
            'last_admin_login_at' => now(),
            'last_admin_login_ip' => $request->ip(),
        ])->save();

        $this->clearFailedAttempts($request, $email);

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

    /**
     * Throw a validation error while the email+IP pair is in a lockout window.
     */
    private function ensureIsNotRateLimited(Request $request, string $email): void
    {
        $lockKey = $this->throttleKey($request, $email).':locked_until';
        $lockedUntil = (int) Cache::get($lockKey, 0);

        if ($lockedUntil <= now()->timestamp) {
            return;
        }

        event(new Lockout($request));

        $seconds = $lockedUntil - now()->timestamp;

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]),
        ]);
    }

    /**
     * Record a failed attempt and apply an exponential lockout once the
     * threshold is exceeded: 2^(attempts - threshold) seconds, capped.
     */
    private function recordFailedAttempt(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);
        $attempts = (int) Cache::get($key.':count', 0) + 1;

        Cache::put($key.':count', $attempts, now()->addMinutes(30));

        if ($attempts < self::MAX_ATTEMPTS_BEFORE_DELAY) {
            return;
        }

        $delaySeconds = min(
            (int) pow(2, $attempts - self::MAX_ATTEMPTS_BEFORE_DELAY),
            self::MAX_DELAY_SECONDS,
        );

        Cache::put(
            $key.':locked_until',
            now()->timestamp + $delaySeconds,
            now()->addSeconds($delaySeconds),
        );
    }

    private function clearFailedAttempts(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);

        Cache::forget($key.':count');
        Cache::forget($key.':locked_until');
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'admin-login|'.Str::transliterate(Str::lower($email)).'|'.$request->ip();
    }
}
