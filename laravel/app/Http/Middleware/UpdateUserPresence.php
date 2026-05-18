<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserPresence
{
    /**
     * Throttle window in seconds. Within this window we only update once per user.
     */
    private const THROTTLE_SECONDS = 60;

    /**
     * Track the latest interaction time for the authenticated user without
     * thrashing the database on every request. Updates are coalesced through
     * a per-user cache lock so that high-traffic endpoints stay cheap.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if (! $user || ! $user->exists) {
            return $response;
        }

        $cacheKey = sprintf('user-presence:%s', $user->getKey());

        if (Cache::has($cacheKey)) {
            return $response;
        }

        $feature = $this->resolveFeature($request);

        try {
            $user->forceFill([
                'last_seen_at' => now(),
                'last_active_feature' => $feature,
            ])->saveQuietly();

            Cache::put($cacheKey, true, self::THROTTLE_SECONDS);
        } catch (\Throwable $exception) {
            // Presence updates must never break a request. Swallow errors and continue.
            report($exception);
        }

        return $response;
    }

    private function resolveFeature(Request $request): ?string
    {
        $route = $request->route();

        if ($route && method_exists($route, 'getName')) {
            $name = $route->getName();

            if (is_string($name) && $name !== '') {
                return mb_substr($name, 0, 64);
            }
        }

        $path = trim($request->path(), '/');

        return $path === '' ? null : mb_substr($path, 0, 64);
    }
}
