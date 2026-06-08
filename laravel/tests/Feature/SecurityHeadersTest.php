<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_dashboard_response_sets_conservative_content_security_policy(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob:", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringNotContainsString('img-src https:', $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_existing_content_security_policy_header_is_not_overwritten(): void
    {
        Route::get('/_test/security/custom-csp', fn () => response('ok')->header('Content-Security-Policy', 'sandbox'));

        $this->get('/_test/security/custom-csp')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_content_security_policy_excludes_unsafe_eval_when_disabled(): void
    {
        config()->set('security.headers.content_security_policy.allow_unsafe_eval', false);

        $response = $this->get(route('dashboard'));

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }
}
