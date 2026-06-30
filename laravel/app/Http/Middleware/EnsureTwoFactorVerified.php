<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Gate admin routes behind a verified two-factor session.
     *
     * Admins without 2FA configured are forced into setup; admins with 2FA
     * configured but not yet verified this session are sent to the challenge.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only admin-family users are subject to mandatory 2FA. Other users
        // never reach these routes, but we fail open for them defensively.
        if (! $user || ! method_exists($user, 'isAdminFamily') || ! $user->isAdminFamily()) {
            return $next($request);
        }

        // Already verified during this session.
        if ($this->twoFactor->isSessionVerified($user, $request)) {
            return $next($request);
        }

        // Trusted device can skip the challenge for the configured window.
        if ($user->hasTwoFactorEnabled() && $this->twoFactor->isDeviceTrusted($user, $request)) {
            $this->twoFactor->markSessionVerified($user, $request);

            return $next($request);
        }

        // 2FA not configured yet: force enrollment before any admin access.
        if (! $user->hasTwoFactorEnabled()) {
            if ($request->routeIs('admin.2fa.setup') || $request->routeIs('admin.2fa.confirm')) {
                return $next($request);
            }

            return redirect()->route('admin.2fa.setup');
        }

        // 2FA configured but this session is not verified yet.
        if ($request->routeIs('admin.2fa.challenge') || $request->routeIs('admin.2fa.verify')) {
            return $next($request);
        }

        return redirect()->route('admin.2fa.challenge');
    }
}
