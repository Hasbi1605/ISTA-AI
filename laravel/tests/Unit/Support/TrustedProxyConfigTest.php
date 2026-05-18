<?php

namespace Tests\Unit\Support;

use App\Support\TrustedProxyConfig;
use Illuminate\Container\Container;
use Tests\TestCase;

class TrustedProxyConfigTest extends TestCase
{
    public function test_from_config_reads_configured_proxy_list(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,172.18.0.0/16']);

        $this->assertSame(['127.0.0.1', '172.18.0.0/16'], TrustedProxyConfig::fromConfig());
    }

    public function test_from_config_is_safe_before_config_repository_is_bound(): void
    {
        $originalContainer = Container::getInstance();

        Container::setInstance(new Container);

        try {
            $this->assertSame([
                '127.0.0.1',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ], TrustedProxyConfig::fromConfig());
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    public function test_from_string_falls_back_to_default_when_empty(): void
    {
        $this->assertSame([
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ], TrustedProxyConfig::fromString(''));
    }
}
