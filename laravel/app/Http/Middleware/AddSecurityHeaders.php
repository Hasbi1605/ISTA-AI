<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->setHeaderIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setHeaderIfMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->setHeaderIfMissing($response, 'Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->setHeaderIfMissing($response, 'X-Frame-Options', 'SAMEORIGIN');

        if ((bool) config('security.headers.content_security_policy.enabled', true)) {
            $this->setHeaderIfMissing($response, 'Content-Security-Policy', $this->contentSecurityPolicy($response));
        }

        return $response;
    }

    private function setHeaderIfMissing(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }

    private function contentSecurityPolicy(Response $response): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'media-src' => ["'self'"],
            'font-src' => ["'self'", 'data:'],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'script-src' => $this->scriptSources($response),
            'connect-src' => ["'self'"],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        if ((bool) config('security.headers.content_security_policy.upgrade_insecure_requests', false)) {
            $directives['upgrade-insecure-requests'] = [];
        }

        if ($origin = $this->originFromUrl((string) config('services.onlyoffice.public_url', ''))) {
            $directives['script-src'][] = $origin;
            $directives['connect-src'][] = $origin;
            $directives['frame-src'][] = $origin;
            $directives['worker-src'][] = $origin;
        }

        if ((bool) config('security.headers.content_security_policy.allow_dev_server', false)) {
            $devSources = ['http://localhost:*', 'http://127.0.0.1:*'];
            $devConnectSources = [...$devSources, 'ws://localhost:*', 'ws://127.0.0.1:*'];

            $directives['script-src'] = [...$directives['script-src'], ...$devSources];
            $directives['style-src'] = [...$directives['style-src'], ...$devSources];
            $directives['connect-src'] = [...$directives['connect-src'], ...$devConnectSources];
        }

        return collect($directives)
            ->map(fn (array $sources, string $name): string => trim($name.' '.implode(' ', array_values(array_unique($sources)))))
            ->implode('; ');
    }

    /**
     * Livewire/Alpine still rely on inline scripts, but eval is opt-in only.
     * Keep this narrow so production does not ship with unsafe-eval by default.
     *
     * @return list<string>
     */
    private function scriptSources(Response $response): array
    {
        $sources = ["'self'", "'unsafe-inline'"];

        if ($this->shouldAllowUnsafeEval($response)) {
            $sources[] = "'unsafe-eval'";
        }

        return $sources;
    }

    private function shouldAllowUnsafeEval(Response $response): bool
    {
        if ((bool) config('security.headers.content_security_policy.allow_unsafe_eval', false)) {
            return true;
        }

        if (! (bool) config('security.headers.content_security_policy.allow_livewire_unsafe_eval', true)) {
            return false;
        }

        if (! $this->isHtmlResponse($response)) {
            return false;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return false;
        }

        return str_contains($content, 'wire:snapshot=')
            || str_contains($content, 'wire:click=')
            || str_contains($content, 'wire:submit=')
            || str_contains($content, 'x-data=')
            || str_contains($content, 'x-on:')
            || str_contains($content, '@click=');
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === ''
            || str_contains(strtolower($contentType), 'text/html');
    }

    private function originFromUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        $origin = strtolower($scheme).'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.((int) $parts['port']);
        }

        return $origin;
    }
}
