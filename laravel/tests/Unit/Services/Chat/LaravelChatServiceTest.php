<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\LaravelChatService;
use App\Services\Document\DocumentPolicyService;
use App\Services\Document\LaravelDocumentRetrievalService;
use App\Services\LangSearchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\Citation;
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

    public function test_stream_parsing_handles_text_delta(): void
    {
        $event = new TextDelta(
            id: '1',
            messageId: 'msg1',
            delta: 'Hello',
            timestamp: time()
        );

        $this->assertInstanceOf(TextDelta::class, $event);
        $this->assertEquals('Hello', $event->delta);
    }

    public function test_citation_emits_source_metadata(): void
    {
        $citationData = new UrlCitation(
            title: 'Test Title',
            url: 'https://example.com'
        );

        $event = new Citation(
            id: '1',
            messageId: 'msg1',
            citation: $citationData,
            timestamp: time()
        );

        $this->assertInstanceOf(Citation::class, $event);
        $this->assertEquals('Test Title', $event->citation->title);
        $this->assertEquals('https://example.com', $event->citation->url);
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

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('stream')
            ->once()
            ->with(Mockery::on(function ($prompt) {
                return $prompt instanceof AgentPrompt
                    && $prompt->prompt === 'RAG_CONTEXT_PROMPT';
            }))
            ->andReturn($this->streamableResponseFromEvents([
                new TextDelta(id: '1', messageId: 'm1', delta: 'Jawaban grounded', timestamp: time()),
            ]));

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('textProviderFor')->andReturn($provider);
        $ai->shouldReceive('textProvider')->zeroOrMoreTimes()->andReturn($provider);

        $this->app->instance(LaravelDocumentRetrievalService::class, $retrieval);
        $this->app->instance(DocumentPolicyService::class, $policy);
        $this->app->instance(AiManager::class, $ai);

        $service = new LaravelChatService();
        $result = $service->chat(
            [['role' => 'user', 'content' => 'apa isi dokumen?']],
            ['doc1.pdf'],
            '1'
        );

        $output = implode('', iterator_to_array($result));

        $this->assertStringContainsString('Jawaban grounded', $output);
        $this->assertStringContainsString('[SOURCES:', $output);
        $this->assertStringContainsString('doc1.pdf', $output);
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

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('stream')
            ->once()
            ->with(Mockery::type(AgentPrompt::class))
            ->andReturn($this->streamableResponseFromEvents([
                new TextDelta(id: '2', messageId: 'm2', delta: 'Jawaban dari web', timestamp: time()),
                new Citation(
                    id: '3',
                    messageId: 'm2',
                    citation: new UrlCitation(title: 'Web Ref', url: 'https://example.com/ref'),
                    timestamp: time()
                ),
            ]));

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('textProviderFor')->andReturn($provider);
        $ai->shouldReceive('textProvider')->zeroOrMoreTimes()->andReturn($provider);

        $this->app->instance(LaravelDocumentRetrievalService::class, $retrieval);
        $this->app->instance(DocumentPolicyService::class, $policy);
        $this->app->instance(AiManager::class, $ai);

        Cache::flush();
        
        $service = new LaravelChatService();
        $result = $service->chat(
            [['role' => 'user', 'content' => 'cari di web ini']],
            ['doc1.pdf'],
            '1'
        );

        $output = implode('', iterator_to_array($result));

        $this->assertStringContainsString('Jawaban dari web', $output);
        $this->assertStringContainsString('[SOURCES:', $output);
        $this->assertStringContainsString('Web Ref', $output);
        $this->assertStringContainsString('example.com\\/ref', $output);
    }

    private function streamFromEvents(array $events): \Generator
    {
        foreach ($events as $event) {
            yield $event;
        }
    }

    private function streamableResponseFromEvents(array $events): StreamableAgentResponse
    {
        return new StreamableAgentResponse(
            invocationId: 'test-invocation',
            generator: fn () => $this->streamFromEvents($events),
            meta: new Meta(provider: 'test', model: 'test-model')
        );
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
