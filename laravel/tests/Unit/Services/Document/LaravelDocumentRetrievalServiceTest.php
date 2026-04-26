<?php

namespace Tests\Unit\Services\Document;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\EmbeddingCascadeService;
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
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunks', $result);
        $this->assertNotEmpty($result['chunks']);
        $this->assertEquals('Ini adalah mock response dari agent prompt', $result['chunks'][0]['content']);
        $this->assertEquals('test.pdf', $result['chunks'][0]['filename']);

        unlink($realPath);
    }

    public function test_search_uses_local_vector_storage_and_cascade(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.embedding_model', 'text-embedding-3-small');
        Config::set('ai.rag.embedding_dimensions', 1536);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'test.txt',
            'file_path' => 'documents/test.txt',
        ]);

        $realPath = storage_path('app/documents/test.txt');
        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($realPath, 'Laravel is a PHP framework.');

        // Mock EmbeddingCascadeService
        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        
        // 1. For query "test"
        $mockCascade->shouldReceive('embed')
            ->with(['test'])
            ->once()
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[1.0, 0.0]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));

        // 2. For document ingestion (chunks)
        $mockCascade->shouldReceive('embed')
            ->with(Mockery::on(fn($args) => count($args) > 0 && str_contains($args[0], 'Laravel')))
            ->once()
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[0.9, 0.1]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'test',
            ['test.txt'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['chunks']);
        
        // Verify chunks were saved to DB
        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'embedding_model' => 'text-embedding-3-small',
        ]);

        unlink($realPath);
    }
}