<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\UpdateUserPresence;
use App\Support\TrustedProxyConfig;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: TrustedProxyConfig::fromConfig(), headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_PREFIX
        );
        $middleware->validateCsrfTokens(except: [
            'onlyoffice/callback/*',
        ]);
        $middleware->append(AddSecurityHeaders::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
            'super_admin' => EnsureUserIsSuperAdmin::class,
            'admin.password_changed' => EnsureAdminPasswordChanged::class,
            'presence' => UpdateUserPresence::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*') || $request->is('horizon') || $request->is('horizon/*')) {
                return route('admin.login');
            }

            return route('login');
        });

        $middleware->appendToGroup('web', UpdateUserPresence::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (Throwable $e) {
            Log::error('Global Exception Caught', [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'message_hash' => hash('sha256', $e->getMessage()),
            ]);
        });

        //
    })->create();
