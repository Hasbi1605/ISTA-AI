<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * Allow only authenticated super admin users.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            abort(403, 'Halaman ini hanya untuk super admin.');
        }

        return $next($request);
    }
}
