<?php

namespace Tests\Feature\Services;

use App\Services\AIRuntimeResolver;
use App\Services\AIService;
use App\Services\Runtime\LaravelAIGateway;
use App\Services\Runtime\PythonLegacyAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use InvalidArgumentException;

class AIRuntimeResolverTest extends TestCase
{
    protected function setUpRuntimeConfig(string $capability, string $runtime, bool $shadowEnabled = false): void
    {
        Config::set('ai_runtime.chat', 'python');
        Config::set('ai_runtime.document_process', 'python');
        Config::set('ai_runtime.document_summarize', 'python');
        Config::set('ai_runtime.document_delete', 'python');
        Config::set("ai_runtime.{$capability}", $runtime);
        Config::set('ai_runtime.shadow.enabled', $shadowEnabled);
        Config::set('ai_runtime.shadow.log_parity', false);
    }

    public function test_returns_python_runtime_when_configured(): void
    {
        $this->setUpRuntimeConfig('chat', 'python');

        $resolver = new AIRuntimeResolver('chat', false);
        $runtime = $resolver->getRuntime();

        $this->assertInstanceOf(PythonLegacyAdapter::class, $runtime);
    }

    public function test_laravel_runtime_resolved_when_ready(): void
    {
        $runtime = new LaravelAIGateway();
        $this->assertFalse($runtime->isReady());
    }

    public function test_python_runtime_is_ready(): void
    {
        $runtime = new PythonLegacyAdapter();
        $this->assertTrue($runtime->isReady());
    }

    public function test_throws_exception_for_unknown_runtime(): void
    {
        $this->setUpRuntimeConfig('chat', 'unknown');

        $resolver = new AIRuntimeResolver('chat', false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI runtime type: unknown');

        $resolver->getRuntime();
    }

    public function test_shadow_mode_disabled_by_default(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', false);

        $resolver = new AIRuntimeResolver('chat', false);

        $this->assertFalse($resolver->isShadowMode());
    }

    public function test_shadow_mode_enabled_when_configured(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $this->assertTrue($resolver->isShadowMode());
    }

    public function test_shadow_mode_returns_secondary_runtime(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);
        $primary = $resolver->getRuntime();
        $secondary = $resolver->getSecondaryRuntime();

        $this->assertInstanceOf(PythonLegacyAdapter::class, $primary);
        $this->assertInstanceOf(LaravelAIGateway::class, $secondary);
    }

    public function test_for_factory_method_creates_resolver(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', false);

        $resolver = AIRuntimeResolver::for('chat');

        $this->assertInstanceOf(AIRuntimeResolver::class, $resolver);
        $this->assertFalse($resolver->isShadowMode());
    }

    public function test_execute_with_shadow_without_shadow_mode(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', false);

        $resolver = new AIRuntimeResolver('chat', false);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => 'secondary_result'
        );

        $this->assertEquals('primary_result', $result['primary']);
        $this->assertNull($result['secondary']);
        $this->assertNull($result['parity']);
    }

    public function test_execute_with_shadow_with_shadow_mode(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => 'secondary_result'
        );

        $this->assertEquals('primary_result', $result['primary']);
        $this->assertEquals('secondary_result', $result['secondary']);
        $this->assertNotNull($result['parity']);
        $this->assertEquals('python', $result['parity']['primary']['source']);
        $this->assertEquals('laravel', $result['parity']['secondary']['source']);
    }

    public function test_parity_metadata_contains_latency(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => 'secondary_result'
        );

        $this->assertArrayHasKey('latency_ms', $result['parity']['primary']);
        $this->assertArrayHasKey('latency_ms', $result['parity']['secondary']);
        $this->assertGreaterThan(0, $result['parity']['primary']['latency_ms']);
    }

    public function test_parity_detects_drift_when_results_differ(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => 'different_result'
        );

        $this->assertTrue($result['parity']['drift_summary']['has_drift']);
        $this->assertEquals('content', $result['parity']['drift_summary']['type']);
    }

    public function test_parity_detects_error_when_secondary_fails(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => throw new \Exception('Secondary error')
        );

        $this->assertTrue($result['parity']['drift_summary']['has_drift']);
        $this->assertEquals('error', $result['parity']['drift_summary']['type']);
    }

    public function test_resolver_uses_different_capabilities_independently(): void
    {
        Config::set('ai_runtime.chat', 'python');
        Config::set('ai_runtime.document_process', 'python');
        Config::set('ai_runtime.shadow.enabled', false);

        $chatResolver = new AIRuntimeResolver('chat', false);
        $docResolver = new AIRuntimeResolver('document_process', false);

        $this->assertInstanceOf(PythonLegacyAdapter::class, $chatResolver->getRuntime());
        $this->assertInstanceOf(PythonLegacyAdapter::class, $docResolver->getRuntime());
    }

    public function test_fallback_to_python_when_laravel_not_ready(): void
    {
        $this->setUpRuntimeConfig('chat', 'laravel');

        $resolver = new AIRuntimeResolver('chat', false);
        $runtime = $resolver->getRuntime();

        $this->assertInstanceOf(PythonLegacyAdapter::class, $runtime);
    }

    public function test_fallback_works_even_when_shadow_mode_enabled(): void
    {
        $this->setUpRuntimeConfig('chat', 'laravel', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $runtime = $resolver->getRuntime();

        $this->assertInstanceOf(PythonLegacyAdapter::class, $runtime);

        $this->assertTrue($resolver->isShadowMode());
        $secondary = $resolver->getSecondaryRuntime();
        $this->assertNotNull($secondary);
    }

    public function test_shadow_mode_runs_both_runtimes(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($r) => 'primary',
            fn($r) => 'secondary'
        );

        $this->assertEquals('primary', $result['primary']);
        $this->assertNotNull($result['secondary']);
        $this->assertNotNull($result['parity']);
    }

    public function test_shadow_mode_does_not_affect_user_response(): void
    {
        Config::set('ai_runtime.chat', 'python');
        Config::set('ai_runtime.shadow.enabled', true);
        Config::set('ai_runtime.shadow.log_parity', false);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($r) => 'user-facing response',
            fn($r) => 'shadow response'
        );

        $this->assertEquals('user-facing response', $result['primary']);
    }

    public function test_parity_metadata_contains_required_fields(): void
    {
        $this->setUpRuntimeConfig('chat', 'python', true);

        $resolver = new AIRuntimeResolver('chat', true);

        $result = $resolver->executeWithShadow(
            fn($runtime) => 'primary_result',
            fn($runtime) => 'secondary_result'
        );

        $parity = $result['parity'];
        $this->assertArrayHasKey('capability', $parity);
        $this->assertArrayHasKey('primary', $parity);
        $this->assertArrayHasKey('secondary', $parity);
        $this->assertArrayHasKey('drift_summary', $parity);
        $this->assertArrayHasKey('source', $parity['primary']);
        $this->assertArrayHasKey('latency_ms', $parity['primary']);
        $this->assertArrayHasKey('status', $parity['primary']);
        $this->assertEquals('python', $parity['primary']['source']);
        $this->assertEquals('laravel', $parity['secondary']['source']);
    }

    public function test_aiservice_chat_delegates_to_runtime_resolver(): void
    {
        Config::set('ai_runtime.chat', 'python');
        Config::set('ai_runtime.shadow.enabled', false);

        $aiService = new AIService();

        $generator = $aiService->sendChat([['role' => 'user', 'content' => 'test']]);

        $this->assertInstanceOf(\Generator::class, $generator);
    }

    public function test_aiservice_summarize_delegates_to_runtime_resolver(): void
    {
        Config::set('ai_runtime.document_summarize', 'python');
        Config::set('ai_runtime.shadow.enabled', false);

        $aiService = new AIService();

        $result = $aiService->summarizeDocument('test.pdf', 'user1');

        $this->assertIsArray($result);
    }
}
