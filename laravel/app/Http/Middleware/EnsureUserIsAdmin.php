<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Allow only authenticated admin or super admin users.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! method_exists($user, 'canAccessAdmin') || ! $user->canAccessAdmin()) {
            abort(403, 'Halaman ini hanya untuk admin.');
        }

        return $next($request);
    }
}
