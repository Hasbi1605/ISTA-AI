<?php

namespace Tests\Unit\Services;

use App\Services\LangSearchService;
use Tests\TestCase;
use Illuminate\Support\Facades\Config;

class LangSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        Config::set('ai.langsearch.api_key', 'test-api-key');
        Config::set('ai.langsearch.api_key_backup', 'test-backup-key');
        Config::set('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        Config::set('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        Config::set('ai.langsearch.rerank_model', 'langsearch-reranker-v1');
        Config::set('ai.langsearch.timeout', 10);
        Config::set('ai.langsearch.rerank_timeout', 8);
        Config::set('ai.langsearch.cache_ttl', 300);
    }

    public function test_service_initializes_with_api_keys(): void
    {
        $service = new LangSearchService();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('apiKeys');
        $property->setAccessible(true);
        
        $apiKeys = $property->getValue($service);
        
        $this->assertCount(2, $apiKeys);
        $this->assertEquals('test-api-key', $apiKeys[0]);
        $this->assertEquals('test-backup-key', $apiKeys[1]);
    }

    public function test_service_works_without_backup_key(): void
    {
        Config::set('ai.langsearch.api_key_backup', null);
        
        $service = new LangSearchService();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('apiKeys');
        $property->setAccessible(true);
        
        $apiKeys = $property->getValue($service);
        
        $this->assertCount(1, $apiKeys);
        $this->assertEquals('test-api-key', $apiKeys[0]);
    }

    public function test_service_returns_empty_when_not_configured(): void
    {
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', null);
        
        $service = new LangSearchService();
        
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isConfigured');
        $method->setAccessible(true);
        
        $this->assertFalse($method->invoke($service));
    }

    public function test_build_search_context_returns_empty_for_empty_results(): void
    {
        $service = new LangSearchService();
        
        $result = $service->buildSearchContext([]);
        
        $this->assertEquals('', $result);
    }

    public function test_build_search_context_formats_results_correctly(): void
    {
        $service = new LangSearchService();
        
        $results = [
            [
                'title' => 'Test Article',
                'snippet' => 'Test description',
                'url' => 'https://example.com',
                'datePublished' => '2026-04-26',
            ],
            [
                'title' => 'Another Article',
                'snippet' => 'Another description',
                'url' => 'https://example.org',
                'datePublished' => '2026-04-25',
            ],
        ];
        
        $result = $service->buildSearchContext($results);
        
        $this->assertStringContainsString('Hasil 1:', $result);
        $this->assertStringContainsString('Test Article', $result);
        $this->assertStringContainsString('https://example.com', $result);
        $this->assertStringContainsString('Hasil 2:', $result);
        $this->assertStringContainsString('Another Article', $result);
    }

    public function test_search_returns_empty_array_when_no_api_key(): void
    {
        Config::set('ai.langsearch.api_key', null);
        Config::set('ai.langsearch.api_key_backup', null);
        
        $service = new LangSearchService();
        
        $result = $service->search('test query');
        
        $this->assertEquals([], $result);
    }

    public function test_rerank_returns_null_for_single_document(): void
    {
        $service = new LangSearchService();
        
        $result = $service->rerank('query', ['single document']);
        
        $this->assertNull($result);
    }
}