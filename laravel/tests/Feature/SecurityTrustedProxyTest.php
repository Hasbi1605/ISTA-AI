<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityTrustedProxyTest extends TestCase
{
    public function test_forwarded_for_is_ignored_from_untrusted_public_remote_address(): void
    {
        Route::get('/_test/security/ip', fn (Request $request) => response($request->ip()));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.77'])
            ->get('/_test/security/ip')
            ->assertOk()
            ->assertSeeText('203.0.113.10');
    }

    public function test_forwarded_for_is_accepted_from_private_internal_proxy_address(): void
    {
        Route::get('/_test/security/trusted-ip', fn (Request $request) => response($request->ip()));

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.77'])
            ->get('/_test/security/trusted-ip')
            ->assertOk()
            ->assertSeeText('198.51.100.77');
    }
}
