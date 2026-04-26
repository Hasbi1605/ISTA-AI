<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\LaravelChatService;
use App\Services\Document\DocumentPolicyService;
use App\Services\Document\LaravelDocumentRetrievalService;
use App\Services\LangSearchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class LaravelChatServiceTest extends TestCase
{
    protected function setUpLaravelAIConfig(): void
    {
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.web_search.enabled', true);
        Config::set('ai.laravel_ai.web_search.provider', 'ddg');
        Config::set('ai.laravel_ai.document_process_enabled', true);
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.laravel_ai.document_delete_enabled', true);
        Config::set('ai.laravel_ai.document_retrieval_enabled', true);
        Config::set('ai.laravel_ai.use_provider_file_search', true);
        Config::set('ai.cascade.enabled', false);
    }

    public function test_chat_with_documents_returns_fallback_message(): void
    {
        $this->setUpLaravelAIConfig();
        Config::set('ai.laravel_ai.document_retrieval_enabled', false);

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

    public function test_realtime_auto_uses_web_search(): void
    {
        $this->setUpLaravelAIConfig();

        $service = new LaravelChatService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('shouldUseWebSearch');
        $method->setAccessible(true);

        $result = $method->invoke($service, false, true, 'hybrid_realtime_auto');

        $this->assertTrue($result);
    }

    public function test_chat_with_documents_falls_back_to_python(): void
    {
        Config::set('ai_runtime.chat', 'laravel');
        Config::set('ai.laravel_ai.api_key', 'test');

        $gateway = new \App\Services\Runtime\LaravelAIGateway();

        $generator = $gateway->chat(
            [['role' => 'user', 'content' => 'test']],
            ['doc1.pdf'],
            'user1',
            false,
            'document_context'
        );

        $result = iterator_to_array($generator);
        $output = implode('', $result);

        $this->assertStringNotContainsString('dokumen aktif belum tersedia', $output);
    }

    public function test_chat_with_documents_success_uses_rag_prompt_and_document_sources(): void
    {
        $this->setUpLaravelAIConfig();
        Config::set('ai.laravel_ai.document_retrieval_enabled', true);

        $retrieval = Mockery::mock(LaravelDocumentRetrievalService::class);
        $retrieval->shouldReceive('searchRelevantChunks')
            ->once()
            ->andReturn([
                'success' => true,
                'chunks' => [
                    ['content' => 'isi chunk', 'filename' => 'doc1.pdf', 'chunk_index' => 0, 'score' => 0.95],
                ],
            ]);
        $retrieval->shouldReceive('buildRagPrompt')
            ->once()
            ->andReturn([
                'prompt' => 'RAG_CONTEXT_PROMPT',
                'sources' => [
                    ['filename' => 'doc1.pdf', 'chunk_index' => 0, 'relevance_score' => 0.95],
                ],
            ]);

        $policy = Mockery::mock(DocumentPolicyService::class);
        $policy->shouldReceive('detectExplicitWebRequest')->once()->andReturn(false);
        $policy->shouldReceive('shouldUseWebSearch')->once()->andReturn([
            'should_search' => false,
            'reason_code' => 'DOC_NO_WEB',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(
                $this->sseBody(['Jawaban grounded']),
                200
            ),
        ]);

        $this->app->instance(LaravelDocumentRetrievalService::class, $retrieval);
        $this->app->instance(DocumentPolicyService::class, $policy);

        $service = new LaravelChatService();
        $result = $service->chat(
            [['role' => 'user', 'content' => 'apa isi dokumen?']],
            ['doc1.pdf'],
            '1'
        );

        $output = $this->collectStream($result);

        $this->assertStringContainsString('[MODEL:Default]', $output);
        $this->assertStringContainsString('Jawaban grounded', $output);
        $this->assertStringContainsString('[SOURCES:', $output);
        $this->assertStringContainsString('doc1.pdf', $output);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/completions'));
    }

    public function test_chat_with_documents_web_fallback_normalizes_stream_and_citations(): void
    {
        $this->setUpLaravelAIConfig();
        Config::set('ai.laravel_ai.document_retrieval_enabled', true);
        Config::set('ai.langsearch.api_key', 'test-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        Config::set('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        
        Http::fake([
            'api.langsearch.com/v1/web-search' => Http::response([
                'data' => [
                    'webPages' => [
                        'value' => [
                            ['name' => 'Web Ref', 'snippet' => 'Reference', 'url' => 'https://example.com/ref'],
                        ]
                    ]
                ]
            ], 200),
            'api.openai.com/v1/chat/completions' => Http::response(
                $this->sseBody(['Jawaban dari web']),
                200
            ),
        ]);

        $retrieval = Mockery::mock(LaravelDocumentRetrievalService::class);
        $retrieval->shouldReceive('searchRelevantChunks')
            ->once()
            ->andReturn([
                'success' => true,
                'chunks' => [],
            ]);

        $policy = Mockery::mock(DocumentPolicyService::class);
        $policy->shouldReceive('detectExplicitWebRequest')->once()->andReturn(true);
        $policy->shouldReceive('shouldUseWebSearch')->once()->andReturn([
            'should_search' => true,
            'reason_code' => 'DOC_WEB_EXPLICIT',
        ]);

        $this->app->instance(LaravelDocumentRetrievalService::class, $retrieval);
        $this->app->instance(DocumentPolicyService::class, $policy);

        Cache::flush();
        
        $service = new LaravelChatService();
        $result = $service->chat(
            [['role' => 'user', 'content' => 'cari di web ini']],
            ['doc1.pdf'],
            '1'
        );

        $output = $this->collectStream($result);

        $this->assertStringContainsString('Jawaban dari web', $output);
        $this->assertStringContainsString('[SOURCES:', $output);
        $this->assertStringContainsString('Web Ref', $output);
        $this->assertStringContainsString('example.com\\/ref', $output);
    }

    public function test_chat_streams_from_chat_completions_endpoint_on_default_node(): void
    {
        $this->setUpLaravelAIConfig();
        Config::set('ai.cascade.enabled', false);
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', null);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(
                $this->sseBody(['Hello ', 'world']),
                200
            ),
        ]);

        $service = new LaravelChatService();
        $generator = $service->chat([['role' => 'user', 'content' => 'hi']], null, '1');
        $output = $this->collectStream($generator);

        $this->assertStringContainsString('[MODEL:Default]', $output);
        $this->assertStringContainsString('Hello world', $output);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/responses'));
    }

    public function test_cascade_falls_back_to_backup_node_on_primary_failure(): void
    {
        $this->setUpLaravelAIConfig();
        Config::set('ai.cascade.enabled', true);
        Config::set('ai.cascade.nodes', [
            [
                'label' => 'Primary',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'primary-key',
                'base_url' => 'https://primary.example.com/v1',
            ],
            [
                'label' => 'Backup',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'backup-key',
                'base_url' => 'https://backup.example.com/v1',
            ],
        ]);
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', null);

        Http::fake([
            'primary.example.com/v1/chat/completions' => Http::response('upstream error', 503),
            'backup.example.com/v1/chat/completions' => Http::response(
                $this->sseBody(['from backup']),
                200
            ),
        ]);

        $service = new LaravelChatService();
        $generator = $service->chat([['role' => 'user', 'content' => 'hi']], null, '1');
        $output = $this->collectStream($generator);

        $this->assertStringContainsString('[MODEL:Primary]', $output);
        $this->assertStringContainsString('[MODEL:Backup]', $output);
        $this->assertStringContainsString('from backup', $output);
    }

    /**
     * Collect all chunks from a chat stream into a single string.
     * Uses foreach (not iterator_to_array) to avoid integer-key collisions
     * between the outer generator and the nested `yield from` generators.
     */
    private function collectStream(\Generator $stream): string
    {
        $output = '';
        foreach ($stream as $chunk) {
            $output .= $chunk;
        }
        return $output;
    }

    /**
     * Build a Server-Sent Events body containing OpenAI-style chat-completion deltas
     * followed by the [DONE] terminator. Used by Http::fake() to simulate the
     * /chat/completions streaming response.
     */
    private function sseBody(array $deltas): string
    {
        $body = '';
        foreach ($deltas as $delta) {
            $payload = json_encode([
                'choices' => [['delta' => ['content' => $delta]]],
            ]);
            $body .= "data: {$payload}\n\n";
        }
        $body .= "data: [DONE]\n\n";
        return $body;
    }

    public function test_perform_lang_search_returns_results_with_rerank(): void
    {
        Config::set('ai.langsearch.api_key', 'test-langsearch-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        Config::set('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        Config::set('ai.langsearch.rerank_model', 'langsearch-reranker-v1');
        
        Http::fake([
            'api.langsearch.com/v1/web-search' => Http::response([
                'data' => [
                    'webPages' => [
                        'value' => [
                            ['name' => 'Result A', 'snippet' => 'Desc A', 'url' => 'https://a.com'],
                            ['name' => 'Result B', 'snippet' => 'Desc B', 'url' => 'https://b.com'],
                        ]
                    ]
                ]
            ], 200),
            'api.langsearch.com/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 1, 'document' => ['url' => 'https://b.com'], 'relevance_score' => 0.9],
                    ['index' => 0, 'document' => ['url' => 'https://a.com'], 'relevance_score' => 0.8],
                ]
            ], 200),
        ]);
        
        Cache::flush();
        
        $service = new LaravelChatService();
        $results = $service->performLangSearch('test query', 'oneWeek', 5);
        
        $this->assertCount(2, $results);
        $this->assertEquals('https://b.com', $results[0]['url']);
        $this->assertEquals('https://a.com', $results[1]['url']);
    }

    public function test_perform_lang_search_falls_back_to_search_when_rerank_fails(): void
    {
        Config::set('ai.langsearch.api_key', 'test-langsearch-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        Config::set('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        Config::set('ai.langsearch.rerank_model', 'langsearch-reranker-v1');
        
        Http::fake([
            'api.langsearch.com/v1/web-search' => Http::response([
                'data' => [
                    'webPages' => [
                        'value' => [
                            ['name' => 'Result A', 'snippet' => 'Desc A', 'url' => 'https://a.com'],
                            ['name' => 'Result B', 'snippet' => 'Desc B', 'url' => 'https://b.com'],
                        ]
                    ]
                ]
            ], 200),
            'api.langsearch.com/v1/rerank' => Http::response(['error' => 'Server Error'], 500),
        ]);
        
        Cache::flush();
        
        $service = new LaravelChatService();
        $results = $service->performLangSearch('test query');
        
        $this->assertCount(2, $results);
        $this->assertEquals('Result A', $results[0]['title']);
    }

    public function test_perform_lang_search_returns_empty_when_not_configured(): void
    {
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', null);
        
        $service = new LaravelChatService();
        $results = $service->performLangSearch('test query');
        
        $this->assertEquals([], $results);
    }

    public function test_perform_lang_search_returns_search_only_when_single_result(): void
    {
        Config::set('ai.langsearch.api_key', 'test-langsearch-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        Config::set('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        
        Http::fake([
            'api.langsearch.com/v1/web-search' => Http::response([
                'data' => [
                    'webPages' => [
                        'value' => [
                            ['name' => 'Single Result', 'snippet' => 'Only one', 'url' => 'https://single.com'],
                        ]
                    ]
                ]
            ], 200),
        ]);
        
        Cache::flush();
        
        $service = new LaravelChatService();
        $results = $service->performLangSearch('test query');
        
        $this->assertCount(1, $results);
        $this->assertEquals('Single Result', $results[0]['title']);
    }

    public function test_uses_langsearch_with_backup_key_only(): void
    {
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.web_search.enabled', true);
        
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', 'backup-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        
        $service = new LaravelChatService();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('useLangSearch');
        $property->setAccessible(true);
        
        $this->assertTrue($property->getValue($service));
    }

    public function test_perform_lang_search_with_backup_key_only_returns_results(): void
    {
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', 'backup-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        
        Http::fake([
            'api.langsearch.com/v1/web-search' => Http::response([
                'data' => [
                    'webPages' => [
                        'value' => [
                            ['name' => 'Result from backup', 'snippet' => 'Desc', 'url' => 'https://backup.com'],
                        ]
                    ]
                ]
            ], 200),
        ]);
        
        Cache::flush();
        
        $service = new LaravelChatService();
        $results = $service->performLangSearch('test query');
        
        $this->assertCount(1, $results);
        $this->assertEquals('Result from backup', $results[0]['title']);
    }
}
