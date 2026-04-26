<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AIRuntimeService;
use App\Services\Document\IngestThrottleService;
use App\Services\Document\Parsing\DocumentParserFactory;
use App\Services\Document\Chunking\TokenCounter;
use App\Services\Document\Chunking\TextChunker;
use App\Services\Document\Chunking\PdrChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    
    public $timeout = 900;

    public function __construct(public Document $document)
    {
    }

    public function handle(AIRuntimeService $AIRuntimeService): void
    {
        try {
            $this->document->update(['status' => 'processing']);

            $filePath = $this->resolveFilePath();
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found. Tried: {$this->document->file_path} and private/{$this->document->file_path}");
            }

            $laravelResult = $this->processWithLaravel($filePath);
            
            if ($laravelResult['status'] === 'success') {
                $this->document->update([
                    'status' => 'ready',
                    'provider_file_id' => $laravelResult['provider_file_id'] ?? null,
                ]);
                return;
            }

            Log::warning('ProcessDocument: Laravel processing failed, falling back to Python', [
                'document_id' => $this->document->id,
                'error' => $laravelResult['message'] ?? 'Unknown',
            ]);

            $result = $AIRuntimeService->documentProcess(
                $filePath,
                $this->document->original_name,
                $this->document->user_id
            );

            if (($result['status'] ?? 'error') === 'success') {
                $this->document->update([
                    'status' => 'ready',
                    'provider_file_id' => $result['provider_file_id'] ?? null,
                ]);
            } else {
                throw new Exception("Process failed: " . ($result['message'] ?? 'Unknown error'));
            }

        } catch (Exception $e) {
            $this->document->update(['status' => 'error']);
            logger()->error("Document processing failed for ID {$this->document->id}: " . $e->getMessage());
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

    protected function processWithLaravel(string $filePath): array
    {
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            $supportedExtensions = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'csv'];
            
            if (!in_array($extension, $supportedExtensions)) {
                return [
                    'status' => 'skip',
                    'message' => "File format {$extension} not supported for Laravel processing",
                ];
            }

            $factory = new DocumentParserFactory();
            
            if (!$factory->supports($filePath)) {
                return [
                    'status' => 'skip',
                    'message' => "No parser available for file format: {$extension}",
                ];
            }

            $pages = $factory->parse($filePath);
            
            if (empty($pages)) {
                return [
                    'status' => 'error',
                    'message' => 'Document parsing produced no content',
                ];
            }

            Log::info('ProcessDocument: Laravel parsed document', [
                'document_id' => $this->document->id,
                'pages' => count($pages),
            ]);

            $chunks = $this->chunkDocument($pages);
            
            if (empty($chunks)) {
                return [
                    'status' => 'error',
                    'message' => 'Document chunking produced no chunks',
                ];
            }

            $this->persistChunks($chunks);

            return [
                'status' => 'success',
                'provider_file_id' => null,
                'pages' => count($pages),
                'chunks' => count($chunks),
            ];

        } catch (\Throwable $e) {
            Log::error('ProcessDocument: Laravel processing error', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
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

        return $chunks;
    }

    protected function persistChunks(array $chunks): void
    {
        $tokenCounter = new TokenCounter();
        $embeddingModel = config('ai.rag.embedding_model', 'text-embedding-3-small');
        $embeddingDimensions = config('ai.rag.embedding_dimensions', 1536);

        DocumentChunk::where('document_id', $this->document->id)->delete();

        $batch = [];
        
        foreach ($chunks as $chunk) {
            $tokens = $tokenCounter->count($chunk['text']);
            
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

            if (count($batch) >= 100) {
                DocumentChunk::insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DocumentChunk::insert($batch);
        }

        Log::info('ProcessDocument: persisted chunks', [
            'document_id' => $this->document->id,
            'total_chunks' => count($chunks),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update(['status' => 'error']);
        logger()->error("Document processing permanently failed for ID {$this->document->id}: " . $exception->getMessage());
    }
}
