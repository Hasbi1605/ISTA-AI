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

    public function test_search_uses_provider_file_id_when_enabled(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', true);
        Config::set('ai.laravel_ai.model', 'gpt-4o-mini');

        $user = User::factory()->create();
        Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'provider.pdf',
            'provider_file_id' => 'file-abcde12345',
            'file_path' => 'documents/missing.pdf',
        ]);

        \Laravel\Ai\AnonymousAgent::fake([
            'Ini adalah mock response dari agent prompt via provider id',
        ]);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'apa itu laravel?',
            ['provider.pdf'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('Ini adalah mock response dari agent prompt via provider id', $result['chunks'][0]['content']);

        \Laravel\Ai\AnonymousAgent::assertPrompted(function ($prompt) {
            return $prompt->model === 'gpt-4o-mini' &&
                $prompt->attachments->isNotEmpty() &&
                $prompt->attachments->first() instanceof \Laravel\Ai\Files\ProviderDocument &&
                $prompt->attachments->first()->id === 'file-abcde12345';
        });
    }

    public function test_search_uses_local_vector_storage_and_cascade(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.hybrid.enabled', false);
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
            ->with(Mockery::on(fn($args) => count($args) > 0 && str_contains($args[0], 'Laravel')), 'text-embedding-3-small')
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
            'embedding_dimensions' => 2,
        ]);

        unlink($realPath);
    }

    public function test_compute_embeddings_stays_scoped_to_active_document(): void
    {
        $user = User::factory()->create();

        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'doc-a.txt',
            'file_path' => 'documents/doc-a.txt',
        ]);

        $otherDocument = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'doc-b.txt',
            'file_path' => 'documents/doc-b.txt',
        ]);

        $activeChunk = DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Doc A content',
        ]);

        $otherChunk = DocumentChunk::create([
            'document_id' => $otherDocument->id,
            'text_content' => 'Doc B content',
            'embedding' => [0.4, 0.5, 0.6],
            'embedding_model' => 'text-embedding-3-large',
            'embedding_dimensions' => 3,
        ]);

        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['Doc A content'], 'text-embedding-3-small')
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[0.9, 0.1]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new class extends LaravelDocumentRetrievalService
        {
            public function computeFor(Document $document, string $model, int $dimensions): void
            {
                $this->computeEmbeddingsForDocument($document, $model, $dimensions);
            }
        };

        $service->computeFor($document, 'text-embedding-3-small', 2);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $activeChunk->id,
            'document_id' => $document->id,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 2,
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $otherChunk->id,
            'document_id' => $otherDocument->id,
            'embedding_model' => 'text-embedding-3-large',
            'embedding_dimensions' => 3,
        ]);
    }

    public function test_search_reembeds_when_existing_chunks_use_different_dimensions(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.hybrid.enabled', false);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'transition.txt',
            'file_path' => 'documents/transition.txt',
        ]);

        $chunk = DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Laravel retrieval example',
            'embedding' => [0.4, 0.5, 0.6],
            'embedding_model' => 'text-embedding-3-large',
            'embedding_dimensions' => 3,
        ]);

        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['laravel'])
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[1.0, 0.0]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['Laravel retrieval example'], 'text-embedding-3-small')
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[0.9, 0.1]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'laravel',
            ['transition.txt'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['chunks']);
        $this->assertSame('transition.txt', $result['chunks'][0]['filename']);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $chunk->id,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 2,
        ]);
    }

    public function test_search_reembeds_stale_chunks_when_document_has_mixed_embedding_states(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.hybrid.enabled', false);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'mixed.txt',
            'file_path' => 'documents/mixed.txt',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Current chunk',
            'embedding' => [1.0, 0.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 2,
        ]);

        $staleChunk = DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Stale chunk',
            'embedding' => [0.4, 0.5, 0.6],
            'embedding_model' => 'text-embedding-3-large',
            'embedding_dimensions' => 3,
        ]);

        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['laravel'])
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[1.0, 0.0]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['Stale chunk'], 'text-embedding-3-small')
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[0.8, 0.2]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'laravel',
            ['mixed.txt'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['chunks']);
        $this->assertDatabaseHas('document_chunks', [
            'id' => $staleChunk->id,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 2,
        ]);
    }

    public function test_search_uses_lexical_fallback_when_query_embedding_fails(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.hybrid.enabled', false);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'lexical.txt',
            'file_path' => 'documents/lexical.txt',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Laravel retrieval lexical fallback',
        ]);

        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['laravel'])
            ->andThrow(new \RuntimeException('Embeddings unavailable'));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'laravel',
            ['lexical.txt'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['chunks']);
        $this->assertStringContainsString('Laravel retrieval lexical fallback', $result['chunks'][0]['content']);
    }

    public function test_search_uses_lexical_fallback_when_document_embedding_refresh_fails(): void
    {
        Config::set('ai.laravel_ai.use_provider_file_search', false);
        Config::set('ai.rag.hybrid.enabled', false);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'status' => 'ready',
            'original_name' => 'doc-refresh-fallback.txt',
            'file_path' => 'documents/doc-refresh-fallback.txt',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Laravel refresh fallback chunk',
        ]);

        $mockCascade = Mockery::mock(EmbeddingCascadeService::class);
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['laravel'])
            ->andReturn(new EmbeddingsResponse(
                embeddings: [[1.0, 0.0]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-small')
            ));
        $mockCascade->shouldReceive('embed')
            ->once()
            ->with(['Laravel refresh fallback chunk'], 'text-embedding-3-small')
            ->andThrow(new \RuntimeException('Document embeddings unavailable'));

        $this->app->instance(EmbeddingCascadeService::class, $mockCascade);

        $service = new LaravelDocumentRetrievalService();

        $result = $service->searchRelevantChunks(
            'laravel',
            ['doc-refresh-fallback.txt'],
            5,
            (string) $user->id
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['chunks']);
        $this->assertStringContainsString('Laravel refresh fallback chunk', $result['chunks'][0]['content']);
    }
}
