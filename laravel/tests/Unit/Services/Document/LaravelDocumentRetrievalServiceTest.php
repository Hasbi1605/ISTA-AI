<?php

namespace Tests\Unit\Services\Document;

use App\Models\Document;
use App\Models\User;
use App\Services\Document\LaravelDocumentRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Mockery;
use Tests\TestCase;

class LaravelDocumentRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_calculate_similarity_uses_sdk_embeddings_api_shape(): void
    {
        Config::set('ai.rag.embedding_model', 'text-embedding-3-small');
        Config::set('ai.rag.embedding_dimensions', 1536);

        $this->app->instance(AiManager::class, Mockery::mock(AiManager::class));

        $service = new LaravelDocumentRetrievalService();

        $provider = new class
        {
            public array $inputs = [];
            public ?int $dimensions = null;
            public ?string $model = null;

            public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30): EmbeddingsResponse
            {
                $this->inputs = $inputs;
                $this->dimensions = $dimensions;
                $this->model = $model;

                return new EmbeddingsResponse(
                    embeddings: [[1.0, 0.0], [1.0, 0.0]],
                    tokens: 2,
                    meta: new Meta(provider: 'test', model: 'embedding-model')
                );
            }
        };

        $score = $this->invokeCalculateSimilarity($service, 'query contoh', 'konten contoh', $provider);

        $this->assertSame(['query contoh', 'konten contoh'], $provider->inputs);
        $this->assertSame(1536, $provider->dimensions);
        $this->assertSame('text-embedding-3-small', $provider->model);
        $this->assertGreaterThan(0.0, $score);
    }

    public function test_calculate_similarity_falls_back_to_lexical_overlap_when_embeddings_fail(): void
    {
        $this->app->instance(AiManager::class, Mockery::mock(AiManager::class));

        $service = new LaravelDocumentRetrievalService();

        $provider = new class
        {
            public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30): EmbeddingsResponse
            {
                throw new \RuntimeException('Embeddings unavailable');
            }
        };

        $score = $this->invokeCalculateSimilarity($service, 'apel mangga', 'apel jeruk', $provider);

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(1.0, $score);
    }

    public function test_search_uses_provider_file_search_when_enabled(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', true);
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'test.pdf',
            'provider_file_id' => null,
            'file_path' => 'documents/test.pdf',
        ]);

        $realPath = storage_path('app/documents/test.pdf');
        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($realPath, 'Test content about Laravel AI SDK for document retrieval');

        \Laravel\Ai\AnonymousAgent::fake([
            'Ini adalah mock response dari agent prompt',
        ]);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'apa itu laravel?',
            ['test.pdf'],
            5,
            (string) $user->id
        );

        $this->assertArrayHasKey('success', $result);
        if (!$result['success']) {
            dump($result);
        }
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunks', $result);
        $this->assertNotEmpty($result['chunks']);
        $this->assertEquals('Ini adalah mock response dari agent prompt', $result['chunks'][0]['content']);
        $this->assertEquals('test.pdf', $result['chunks'][0]['filename']);

        \Laravel\Ai\AnonymousAgent::assertPrompted(function ($prompt) {
            return $prompt->model === 'gpt-4o-mini' &&
                   $prompt->attachments->isNotEmpty() &&
                   $prompt->attachments->first() instanceof \Laravel\Ai\Files\LocalDocument;
        });

        unlink($realPath);
    }

    public function test_search_uses_provider_file_id_and_uses_config_model_when_enabled(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', true);
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'test_provider.pdf',
            'provider_file_id' => 'file-abcde12345',
            'file_path' => 'documents/missing.pdf', // file lokal sengaja tidak ada
        ]);

        \Laravel\Ai\AnonymousAgent::fake([
            'Ini adalah mock response dari agent prompt via provider id',
        ]);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'apa itu laravel?',
            ['test_provider.pdf'],
            5,
            (string) $user->id
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunks', $result);
        $this->assertEquals('Ini adalah mock response dari agent prompt via provider id', $result['chunks'][0]['content']);

        \Laravel\Ai\AnonymousAgent::assertPrompted(function ($prompt) {
            return $prompt->model === 'gpt-4o-mini' &&
                   $prompt->attachments->isNotEmpty() &&
                   $prompt->attachments->first() instanceof \Laravel\Ai\Files\ProviderDocument &&
                   $prompt->attachments->first()->id === 'file-abcde12345';
        });
    }

    public function test_search_uses_local_extraction_when_provider_file_search_disabled(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'test.txt',
            'provider_file_id' => null,
            'file_path' => 'documents/test.txt',
        ]);

        // Use real file in storage/app directory
        $realPath = storage_path('app/documents/test.txt');
        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($realPath, 'This is a test document content about Laravel.\nIt contains important information about the framework.');

        // Use mock embeddings that returns high similarity so search succeeds
        $mockProvider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);
        $mockProvider->shouldReceive('embeddings')->andReturn(
            new EmbeddingsResponse(
                embeddings: [[1.0, 0.0], [1.0, 0.0]],
                tokens: 2,
                meta: new Meta(provider: 'test', model: 'embedding-model')
            )
        );

        $mockAiManager = Mockery::mock(AiManager::class);
        $mockAiManager->shouldReceive('textProvider')->andReturn($mockProvider);
        $this->app->instance(AiManager::class, $mockAiManager);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'test',
            ['test.txt'],
            5,
            (string) $user->id
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunks', $result);
        $this->assertNotEmpty($result['chunks']);
        $this->assertEquals('test.txt', $result['chunks'][0]['filename']);
        $this->assertStringContainsString('This is a test document content about Laravel.', $result['chunks'][0]['content']);

        unlink($realPath);
    }

    private function invokeCalculateSimilarity(
        LaravelDocumentRetrievalService $service,
        string $query,
        string $content,
        object $provider
    ): float {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('calculateSimilarity');
        $method->setAccessible(true);

        return $method->invoke($service, $query, $content, $provider);
    }
}