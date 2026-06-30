<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminSessionLifetime
{
    /**
     * Enforce an absolute (non-sliding) lifetime on admin sessions.
     *
     * Unlike the idle session lifetime, this caps how long an admin session
     * may live regardless of activity. Once exceeded the admin is logged out
     * and bounced to the admin login page.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'isAdminFamily') || ! $user->isAdminFamily() || ! $request->hasSession()) {
            return $next($request);
        }

        $startedAt = (int) $request->session()->get('admin_session_started_at', 0);

        // First admin request in this session: stamp the start time.
        if ($startedAt <= 0) {
            $request->session()->put('admin_session_started_at', now()->timestamp);

            return $next($request);
        }

        $absoluteLifetimeMinutes = max(1, (int) config('session.admin_absolute_lifetime', 720));

        if ((now()->timestamp - $startedAt) <= $absoluteLifetimeMinutes * 60) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withErrors(['email' => 'Sesi admin telah berakhir. Silakan login kembali.']);
    }
}
