<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    /**
     * Force admin/super_admin with force_password_change=true to update password
     * before they can use any admin route.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'isAdminFamily') || ! $user->isAdminFamily()) {
            return $next($request);
        }

        if (! ($user->force_password_change ?? false)) {
            return $next($request);
        }

        // Already on the force-change route or submitting the form? Allow.
        if ($request->routeIs('admin.password.change')
            || $request->routeIs('admin.password.update')
            || $request->routeIs('admin.logout')) {
            return $next($request);
        }

        return redirect()->route('admin.password.change');
    }
}
