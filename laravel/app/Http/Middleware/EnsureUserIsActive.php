<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Block authenticated users whose account has been disabled.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isActive') && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Akun sedang dinonaktifkan.',
                ], 403);
            }

            return redirect()->route('login')
                ->withErrors(['form.email' => 'Akun sedang dinonaktifkan. Hubungi admin.']);
        }

        return $next($request);
    }
}
