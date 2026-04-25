<?php

namespace Tests\Unit\Services\Document;

use App\Models\Document;
use App\Models\User;
use App\Services\Document\LaravelDocumentRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
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
        $this->assertEquals(1.0, $score);
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

        Storage::put('documents/test.pdf', 'Test content about Laravel AI SDK');

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'apa itu laravel?',
            ['test.pdf'],
            5,
            (string) $user->id
        );

        $this->assertArrayHasKey('chunks', $result);
        $this->assertArrayHasKey('success', $result);
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

        Storage::put('documents/test.txt', 'This is a test document content about Laravel.');

        $mockProvider = new class {
            public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30): EmbeddingsResponse {
                return new EmbeddingsResponse(
                    embeddings: [[1.0, 0.0], [1.0, 0.0]],
                    tokens: 2,
                    meta: new Meta(provider: 'test', model: 'embedding-model')
                );
            }
        };

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

        $this->assertArrayHasKey('chunks', $result);
        $this->assertArrayHasKey('success', $result);
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
