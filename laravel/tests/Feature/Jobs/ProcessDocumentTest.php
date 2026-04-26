<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AIRuntimeService;
use App\Services\Document\Parsing\DocumentParserFactory;
use App\Services\Document\Chunking\TextChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Mockery;
use Exception;

class ProcessDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_updates_status_to_ready_on_success(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/dummy.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'dummy.pdf',
            'original_name' => 'dummy.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'pending',
        ]);

        $mockRuntime = Mockery::mock(AIRuntimeService::class);
        $mockRuntime->shouldReceive('documentProcess')
            ->once()
            ->andReturn(['status' => 'success', 'message' => 'ok']);

        $job = new ProcessDocument($document);
        $job->handle($mockRuntime);

        $this->assertEquals('ready', $document->fresh()->status);
    }

    public function test_chunk_document_creates_chunks_from_pages(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/test.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'test.pdf',
            'original_name' => 'test.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'pending',
        ]);

        $job = new ProcessDocument($document);
        
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('chunkDocument');
        $method->setAccessible(true);
        
        $pages = [
            ['page_content' => 'This is page one with some content that should be chunked properly.', 'page_number' => 1],
            ['page_content' => 'This is page two with different content for testing purposes.', 'page_number' => 2],
        ];
        
        $chunks = $method->invoke($job, $pages);
        
        $this->assertNotEmpty($chunks);
        
        foreach ($chunks as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('chunk_type', $chunk);
            $this->assertStringContainsString(' ', $chunk['text']);
        }
    }

    public function test_persist_chunks_saves_chunks_to_database(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/test.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'test.pdf',
            'original_name' => 'test.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'pending',
        ]);

        $job = new ProcessDocument($document);
        
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('persistChunks');
        $method->setAccessible(true);
        
        $chunks = [
            [
                'text' => 'First chunk content',
                'chunk_type' => 'child',
                'parent_id' => null,
                'parent_index' => 0,
                'child_index' => 0,
                'metadata' => ['filename' => 'test.pdf', 'user_id' => (string) $user->id],
            ],
            [
                'text' => 'Second chunk content',
                'chunk_type' => 'child',
                'parent_id' => null,
                'parent_index' => 1,
                'child_index' => 1,
                'metadata' => ['filename' => 'test.pdf', 'user_id' => (string) $user->id],
            ],
        ];
        
        $method->invoke($job, $chunks);
        
        $this->assertDatabaseCount('document_chunks', 2);
        
        $savedChunks = DocumentChunk::where('document_id', $document->id)->get();
        $this->assertEquals(2, $savedChunks->count());
        
        $firstChunk = $savedChunks->where('child_index', 0)->first();
        $this->assertEquals('First chunk content', $firstChunk->text_content);
    }

    public function test_persist_chunks_deletes_existing_chunks(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/test.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'test.pdf',
            'original_name' => 'test.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'pending',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_type' => 'child',
            'text_content' => 'Old chunk that should be deleted',
            'parent_index' => 0,
            'child_index' => 0,
        ]);

        $this->assertDatabaseCount('document_chunks', 1);

        $job = new ProcessDocument($document);
        
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('persistChunks');
        $method->setAccessible(true);
        
        $newChunks = [
            [
                'text' => 'New chunk content',
                'chunk_type' => 'child',
                'parent_id' => null,
                'parent_index' => 0,
                'child_index' => 0,
                'metadata' => ['filename' => 'test.pdf', 'user_id' => (string) $user->id],
            ],
        ];
        
        $method->invoke($job, $newChunks);
        
        $this->assertDatabaseCount('document_chunks', 1);
        
        $chunk = DocumentChunk::where('document_id', $document->id)->first();
        $this->assertEquals('New chunk content', $chunk->text_content);
    }

    public function test_text_chunker_produces_valid_chunks(): void
    {
        $chunker = new TextChunker(50, 10);
        
        $pages = [
            ['page_content' => 'This is a test document with multiple words that need to be properly chunked without losing spaces between words.', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages);
        
        $this->assertNotEmpty($chunks);
        
        foreach ($chunks as $chunk) {
            $this->assertStringContainsString(' ', $chunk);
            $this->assertGreaterThan(0, strlen(trim($chunk)));
        }
    }
}
