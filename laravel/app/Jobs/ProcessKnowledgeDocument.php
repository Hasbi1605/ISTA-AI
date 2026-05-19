<?php

namespace App\Jobs;

use App\Models\AIUsageEvent;
use App\Models\KnowledgeDocument;
use App\Services\Admin\AIUsageEventService;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ProcessKnowledgeDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnowledgeDocument $document)
    {
    }

    public function handle(KnowledgeLifecycleService $lifecycle, AIUsageEventService $events): void
    {
        $fresh = $this->document->fresh();

        if ($fresh === null) {
            logger()->info('ProcessKnowledgeDocument skipped: document deleted before processing', [
                'document_id' => $this->document->id,
            ]);

            return;
        }

        $this->document = $fresh;
        $startedAt = microtime(true);
        $requestId = $events->newRequestId();

        $events->started(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: $fresh->uploaded_by_id,
            metadata: [
                'document_id' => (int) $fresh->id,
                'origin' => 'admin',
                'outcome' => 'processing_started',
                'job_class' => static::class,
                'job_attempts' => $this->attempts(),
            ],
            requestId: $requestId,
            subject: $fresh,
        );

        if (! $fresh->file_path) {
            $lifecycle->recordProcessingFailure($fresh, 'missing_file_path', 'file_path is empty');
            $this->logFailure($events, $fresh, $requestId, $startedAt, 'missing_file_path');

            return;
        }

        $absolutePath = Storage::disk('local')->path($fresh->file_path);

        if (! is_string($absolutePath) || ! is_file($absolutePath)) {
            $lifecycle->recordProcessingFailure($fresh, 'file_not_found', 'File knowledge tidak ditemukan di storage.');
            $this->logFailure($events, $fresh, $requestId, $startedAt, 'file_not_found');

            return;
        }

        $base = rtrim((string) config('services.ai_document_service.url', config('services.ai_service.url', 'http://127.0.0.1:8001')), '/');
        $url = $base.'/api/knowledge/process';
        $token = config('services.ai_document_service.token', config('services.ai_service.token'));

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            $lifecycle->recordProcessingFailure($fresh, 'file_unreadable', 'File knowledge tidak dapat dibaca.');
            $this->logFailure($events, $fresh, $requestId, $startedAt, 'file_unreadable');

            return;
        }

        try {
            $response = Http::timeout(900)
                ->withHeaders(['Authorization' => 'Bearer '.$token])
                ->attach('file', $handle, $fresh->original_name)
                ->post($url, [
                    'document_id' => (string) $fresh->id,
                    'knowledge_source_id' => (string) ($fresh->knowledge_source_id ?? ''),
                    'scope' => $fresh->scope ?: KnowledgeDocument::SCOPE_GLOBAL_INTERNAL,
                    'audience' => $fresh->audience ?: KnowledgeDocument::AUDIENCE_ALL_USERS,
                    'title' => (string) $fresh->title,
                ]);
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $lifecycle->recordProcessingFailure($fresh, 'http_exception', $e->getMessage());
            $this->logFailure($events, $fresh, $requestId, $startedAt, 'http_exception');

            throw $e;
        }

        if (is_resource($handle)) {
            fclose($handle);
        }

        if (! $response->successful()) {
            $lifecycle->recordProcessingFailure($fresh, 'microservice_error', (string) $response->body());
            $this->logFailure($events, $fresh, $requestId, $startedAt, 'microservice_error');

            throw new \RuntimeException('Knowledge ingest microservice error: '.$response->body());
        }

        $payload = $response->json() ?? [];
        $provider = is_string($payload['embedding_provider'] ?? null) ? $payload['embedding_provider'] : null;
        $chunkCount = isset($payload['chunk_count']) ? (int) $payload['chunk_count'] : null;
        $successful = isset($payload['successful_chunks']) ? (int) $payload['successful_chunks'] : $chunkCount;
        $failed = isset($payload['failed_chunks']) ? (int) $payload['failed_chunks'] : 0;

        $lifecycle->recordProcessingSuccess($fresh, $provider, $chunkCount, $successful, $failed);

        $events->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: $fresh->uploaded_by_id,
            metadata: [
                'document_id' => (int) $fresh->id,
                'origin' => 'admin',
                'outcome' => 'processing_succeeded',
                'provider' => $provider,
                'job_class' => static::class,
                'job_attempts' => $this->attempts(),
            ],
            requestId: $requestId,
            latencyMs: $events->latencyMsSince($startedAt),
            subject: $fresh,
        );
    }

    public function failed(\Throwable $exception): void
    {
        $events = app(AIUsageEventService::class);
        $lifecycle = app(KnowledgeLifecycleService::class);

        $document = $this->document->fresh() ?? $this->document;

        $lifecycle->recordProcessingFailure($document, 'job_failed', $exception->getMessage());

        $events->failed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: $document->uploaded_by_id,
            metadata: [
                'document_id' => (int) $document->id,
                'origin' => 'admin',
                'outcome' => 'processing_failed',
                'job_class' => static::class,
                'job_attempts' => $this->attempts(),
            ],
            errorCode: 'job_failed',
            subject: $document,
        );
    }

    private function logFailure(AIUsageEventService $events, KnowledgeDocument $document, string $requestId, float $startedAt, string $errorCode): void
    {
        $events->failed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: $document->uploaded_by_id,
            metadata: [
                'document_id' => (int) $document->id,
                'origin' => 'admin',
                'outcome' => 'processing_failed',
                'reason' => $errorCode,
                'job_class' => static::class,
                'job_attempts' => $this->attempts(),
            ],
            requestId: $requestId,
            latencyMs: $events->latencyMsSince($startedAt),
            errorCode: $errorCode,
            subject: $document,
        );
    }
}
