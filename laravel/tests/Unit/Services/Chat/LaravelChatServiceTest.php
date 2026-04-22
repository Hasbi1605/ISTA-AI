<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\LaravelChatService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LaravelChatServiceTest extends TestCase
{
    protected function setUpLaravelAIConfig(): void
    {
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.web_search.enabled', true);
        Config::set('ai.laravel_ai.web_search.provider', 'ddg');
    }

    public function test_chat_with_documents_returns_fallback_message(): void
    {
        $this->setUpLaravelAIConfig();

        $service = new LaravelChatService();

        $result = $service->chat(
            [['role' => 'user', 'content' => 'test query']],
            ['doc1.pdf'],
            'user1'
        );

        $output = '';
        foreach ($result as $chunk) {
            $output .= $chunk;
        }

        $this->assertStringContainsString('dokumen aktif', $output);
    }

    public function test_should_use_web_search_when_forced(): void
    {
        $this->setUpLaravelAIConfig();

        $service = new LaravelChatService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('shouldUseWebSearch');
        $method->setAccessible(true);

        $result = $method->invoke($service, true, false, null);

        $this->assertTrue($result);
    }

    public function test_should_not_use_web_search_when_not_forced_and_auto_disabled(): void
    {
        $this->setUpLaravelAIConfig();

        $service = new LaravelChatService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('shouldUseWebSearch');
        $method->setAccessible(true);

        $result = $method->invoke($service, false, false, null);

        $this->assertFalse($result);
    }

    public function test_should_use_web_search_with_web_policy(): void
    {
        $this->setUpLaravelAIConfig();

        $service = new LaravelChatService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('shouldUseWebSearch');
        $method->setAccessible(true);

        $result = $method->invoke($service, false, true, 'web-only');

        $this->assertTrue($result);
    }

    public function test_should_not_use_web_search_when_disabled_in_config(): void
    {
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.web_search.enabled', false);

        $service = new LaravelChatService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('shouldUseWebSearch');
        $method->setAccessible(true);

        $result = $method->invoke($service, true, true, null);

        $this->assertFalse($result);
    }

    public function test_is_ready_returns_true_when_api_key_set(): void
    {
        $this->setUpLaravelAIConfig();

        $gateway = new \App\Services\Runtime\LaravelAIGateway();

        $this->assertTrue($gateway->isReady());
    }

    public function test_is_ready_returns_false_when_api_key_not_set(): void
    {
        Config::set('ai.laravel_ai.api_key', null);

        $gateway = new \App\Services\Runtime\LaravelAIGateway();

        $this->assertFalse($gateway->isReady());
    }
}