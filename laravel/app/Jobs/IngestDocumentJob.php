<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Document\Chunking\TokenCounter;
use App\Services\Document\Chunking\TextChunker;
use App\Services\Document\Chunking\PdrChunker;
use App\Services\Document\IngestThrottleService;
use App\Services\Document\Parsing\DocumentParserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 900;

    public function __construct(public Document $document)
    {
    }

    protected IngestThrottleService $throttleService;

    public function handle(IngestThrottleService $throttleService): void
    {
        $this->throttleService = $throttleService;

        try {
            $this->document->update(['status' => 'processing']);

            $filePath = $this->resolveFilePath();
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found: {$filePath}");
            }

            Log::info('IngestDocumentJob: starting ingest', [
                'document_id' => $this->document->id,
                'filename' => $this->document->original_name,
                'file_path' => $filePath,
            ]);

            $pages = $this->parseDocument($filePath);
            
            if (empty($pages)) {
                throw new Exception('Document parsing produced no content');
            }

            $chunks = $this->chunkDocument($pages);
            
            if (empty($chunks)) {
                throw new Exception('Document chunking produced no chunks');
            }

            $this->persistChunksWithThrottle($chunks);

            $this->document->update(['status' => 'ready']);

            Log::info('IngestDocumentJob: ingest completed', [
                'document_id' => $this->document->id,
                'total_chunks' => count($chunks),
            ]);

        } catch (Exception $e) {
            $this->document->update(['status' => 'error']);
            Log::error('IngestDocumentJob: ingest failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function resolveFilePath(): string
    {
        $filePath = Storage::disk('local')->path($this->document->file_path);
        
        if (!file_exists($filePath)) {
            $filePath = Storage::disk('local')->path('private/' . $this->document->file_path);
        }
        
        return $filePath;
    }

    protected function parseDocument(string $filePath): array
    {
        $factory = new DocumentParserFactory();
        
        $pages = $factory->parse($filePath);

        Log::info('IngestDocumentJob: parsed document', [
            'document_id' => $this->document->id,
            'pages' => count($pages),
        ]);

        return $pages;
    }

    protected function chunkDocument(array $pages): array
    {
        $usePdr = config('ai.rag.pdr.enabled', true);
        $filename = $this->document->original_name;
        $userId = (string) $this->document->user_id;

        if ($usePdr) {
            $chunker = new PdrChunker();
            $chunks = $chunker->chunk($pages, $filename, $userId);
        } else {
            $chunker = new TextChunker();
            $texts = $chunker->chunk($pages);
            
            $chunks = array_map(function ($text, $index) use ($filename, $userId) {
                return [
                    'text' => $text,
                    'chunk_type' => 'child',
                    'parent_id' => null,
                    'parent_index' => $index,
                    'child_index' => $index,
                    'metadata' => [
                        'filename' => $filename,
                        'user_id' => $userId,
                        'chunk_type' => 'child',
                        'child_index' => $index,
                    ],
                ];
            }, $texts, array_keys($texts));
        }

        Log::info('IngestDocumentJob: chunked document', [
            'document_id' => $this->document->id,
            'chunks' => count($chunks),
            'mode' => $usePdr ? 'pdr' : 'standard',
        ]);

        return $chunks;
    }

    protected function persistChunks(array $chunks): void
    {
        $this->persistChunksWithThrottle($chunks);
    }

    protected function persistChunksWithThrottle(array $chunks): void
    {
        $tokenCounter = new TokenCounter();
        $embeddingModel = config('ai.rag.embedding_model', 'text-embedding-3-small');
        $embeddingDimensions = config('ai.rag.embedding_dimensions', 1536);

        DocumentChunk::where('document_id', $this->document->id)->delete();

        $tokens = array_map(fn($chunk) => $tokenCounter->count($chunk['text']), $chunks);
        $batches = $this->throttleService->createBatches($chunks, $tokens);

        foreach ($batches as $batchData) {
            $batch = [];
            
            foreach ($batchData['chunks'] as $chunk) {
                $batch[] = [
                    'document_id' => $this->document->id,
                    'parent_id' => $chunk['parent_id'],
                    'chunk_type' => $chunk['chunk_type'],
                    'parent_index' => $chunk['parent_index'],
                    'child_index' => $chunk['child_index'] ?? null,
                    'page_number' => 1,
                    'text_content' => $chunk['text'],
                    'embedding' => null,
                    'embedding_model' => $embeddingModel,
                    'embedding_dimensions' => $embeddingDimensions,
                ];
            }

            if (!empty($batch)) {
                DocumentChunk::insert($batch);
            }

            $delay = $this->throttleService->getBatchDelay();
            if ($delay > 0) {
                usleep((int)($delay * 1000000));
            }
        }

        Log::info('IngestDocumentJob: persisted chunks with throttling', [
            'document_id' => $this->document->id,
            'total_chunks' => count($chunks),
            'total_batches' => count($batches),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update(['status' => 'error']);
        
        Log::error('IngestDocumentJob: permanently failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}