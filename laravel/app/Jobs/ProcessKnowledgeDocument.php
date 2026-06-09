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
use Illuminate\Support\Str;

class ProcessKnowledgeDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    protected string $claimToken;

    public function __construct(public KnowledgeDocument $document, ?string $claimToken = null)
    {
        $this->claimToken = $claimToken ?: Str::uuid()->toString();
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

        if (! $this->claimProcessingSlot($fresh)) {
            logger()->info('ProcessKnowledgeDocument skipped: claim superseded by newer job', [
                'document_id' => $fresh->id,
                'current_status' => $fresh->status,
            ]);

            return;
        }

        $fresh = $this->document;
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
            if ($lifecycle->recordProcessingFailure($fresh, 'missing_file_path', 'file_path is empty', $this->claimToken)) {
                $this->logFailure($events, $fresh, $requestId, $startedAt, 'missing_file_path');
            }

            return;
        }

        $absolutePath = Storage::disk('local')->path($fresh->file_path);

        if (! is_string($absolutePath) || ! is_file($absolutePath)) {
            if ($lifecycle->recordProcessingFailure($fresh, 'file_not_found', 'File knowledge tidak ditemukan di storage.', $this->claimToken)) {
                $this->logFailure($events, $fresh, $requestId, $startedAt, 'file_not_found');
            }

            return;
        }

        $base = rtrim((string) config('services.ai_document_service.url', config('services.ai_service.url', 'http://127.0.0.1:8001')), '/');
        $url = $base.'/api/knowledge/process';
        $token = config('services.ai_document_service.token', config('services.ai_service.token'));

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            if ($lifecycle->recordProcessingFailure($fresh, 'file_unreadable', 'File knowledge tidak dapat dibaca.', $this->claimToken)) {
                $this->logFailure($events, $fresh, $requestId, $startedAt, 'file_unreadable');
            }

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

            $recorded = $lifecycle->recordProcessingFailure($fresh, 'http_exception', $e->getMessage(), $this->claimToken);
            if ($recorded) {
                $this->logFailure($events, $fresh, $requestId, $startedAt, 'http_exception');
            }

            if ($recorded) {
                throw $e;
            }

            return;
        }

        if (is_resource($handle)) {
            fclose($handle);
        }

        if (! $response->successful()) {
            $recorded = $lifecycle->recordProcessingFailure($fresh, 'microservice_error', (string) $response->body(), $this->claimToken);
            if ($recorded) {
                $this->logFailure($events, $fresh, $requestId, $startedAt, 'microservice_error');
            }

            if ($recorded) {
                throw new \RuntimeException('Knowledge ingest microservice error: '.$response->body());
            }

            return;
        }

        $payload = $response->json() ?? [];
        $provider = is_string($payload['embedding_provider'] ?? null) ? $payload['embedding_provider'] : null;
        $chunkCount = isset($payload['chunk_count']) ? (int) $payload['chunk_count'] : null;
        $successful = isset($payload['successful_chunks']) ? (int) $payload['successful_chunks'] : $chunkCount;
        $failed = isset($payload['failed_chunks']) ? (int) $payload['failed_chunks'] : 0;

        if (! $lifecycle->recordProcessingSuccess($fresh, $provider, $chunkCount, $successful, $failed, $this->claimToken)) {
            return;
        }

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

        if (! $lifecycle->recordProcessingFailure($document, 'job_failed', $exception->getMessage(), $this->claimToken)) {
            return;
        }

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

    private function claimProcessingSlot(KnowledgeDocument $document): bool
    {
        if ($document->processing_claim_token === $this->claimToken) {
            return true;
        }

        if ($document->processing_claim_token !== null && $document->processing_claim_token !== '') {
            return false;
        }

        $claimed = KnowledgeDocument::query()
            ->whereKey($document->id)
            ->where('status', KnowledgeDocument::STATUS_PROCESSING)
            ->whereNull('processing_claim_token')
            ->update(['processing_claim_token' => $this->claimToken]);

        if ($claimed === 0) {
            return false;
        }

        $this->document = $document->fresh() ?? $document;

        return true;
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
