<?php

namespace Tests\Unit;

use App\Services\AIService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    public function test_ai_service_normalizes_quoted_service_config_values(): void
    {
        config()->set('services.ai_service.url', ' "http://python-ai:8001/" ');
        config()->set('services.ai_document_service.url', " 'http://python-ai-docs:8002/' ");
        config()->set('services.ai_service.token', " 'internal-token' ");
        config()->set('services.ai_service.retries', ' "3" ');
        config()->set('services.ai_service.retry_delay_ms', " '450' ");
        config()->set('services.ai_service.connect_timeout', ' "11.5" ');
        config()->set('services.ai_service.timeout', " '121.5' ");
        config()->set('services.ai_service.read_timeout', ' "122.5" ');

        $service = new AIService;
        $reflection = new \ReflectionClass($service);

        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrl->setAccessible(true);
        $documentBaseUrl = $reflection->getProperty('documentBaseUrl');
        $documentBaseUrl->setAccessible(true);
        $token = $reflection->getProperty('token');
        $token->setAccessible(true);
        $maxRetries = $reflection->getProperty('maxRetries');
        $maxRetries->setAccessible(true);
        $retryDelayMs = $reflection->getProperty('retryDelayMs');
        $retryDelayMs->setAccessible(true);
        $client = $reflection->getProperty('client');
        $client->setAccessible(true);

        $this->assertSame('http://python-ai:8001', $baseUrl->getValue($service));
        $this->assertSame('http://python-ai-docs:8002', $documentBaseUrl->getValue($service));
        $this->assertSame('internal-token', $token->getValue($service));
        $this->assertSame(3, $maxRetries->getValue($service));
        $this->assertSame(450, $retryDelayMs->getValue($service));

        $clientConfig = $client->getValue($service)->getConfig();

        $this->assertSame(11.5, $clientConfig['connect_timeout']);
        $this->assertSame(121.5, $clientConfig['timeout']);
        $this->assertSame(122.5, $clientConfig['read_timeout']);
    }

    public function test_ai_service_yields_sentinel_prefix_on_network_error(): void
    {
        config()->set('services.ai_service.url', 'http://python-ai:8001');
        config()->set('services.ai_service.retries', 1);
        config()->set('services.ai_service.retry_delay_ms', 0);

        $mock = new MockHandler([
            new RequestException('Connection refused', new Request('POST', '/api/chat')),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new AIService;
        $reflection = new \ReflectionClass($service);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $mockClient);

        $chunks = iterator_to_array($service->sendChat([['role' => 'user', 'content' => 'test']]));

        $this->assertCount(1, $chunks);
        $this->assertStringStartsWith(AIService::ERROR_SENTINEL, $chunks[0]);
    }

    public function test_ai_service_error_sentinel_constant_is_unique(): void
    {
        $this->assertStringStartsWith('[', AIService::ERROR_SENTINEL);
        $this->assertStringEndsWith(']', AIService::ERROR_SENTINEL);
        $this->assertStringNotContainsString('MODEL', AIService::ERROR_SENTINEL);
        $this->assertStringNotContainsString('SOURCES', AIService::ERROR_SENTINEL);
    }

    public function test_ai_service_redacts_sensitive_log_messages(): void
    {
        $service = new AIService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sanitizeLogMessage');
        $method->setAccessible(true);

        $message = 'Authorization: Bearer super-secret-token token=raw-secret GOCSPX-client-secret';

        $this->assertSame(
            'Authorization: Bearer [REDACTED] token=[REDACTED] GOCSPX-[REDACTED]',
            $method->invoke($service, $message)
        );
    }
}
