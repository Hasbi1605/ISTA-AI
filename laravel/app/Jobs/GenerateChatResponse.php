<?php

namespace App\Jobs;

use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Admin\AIUsageEventService;
use App\Services\AIService;
use App\Services\ChatOrchestrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateChatResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 180;

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, int|string>  $conversationDocuments
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly array $history,
        public readonly array $conversationDocuments = [],
        public readonly bool $webSearchMode = false,
        public readonly ?string $requestId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(AIService $aiService, ChatOrchestrationService $orchestrator, ?AIUsageEventService $usageEvents = null): void
    {
        $usageEvents = $usageEvents ?? app(AIUsageEventService::class);

        Auth::loginUsingId($this->userId);
        set_time_limit($this->timeout);

        $requestId = $this->requestId ?? (string) Str::uuid();
        $jobStartMs = microtime(true) * 1000;
        $jobStartedAt = microtime(true);
        $hasDocumentContext = ! empty($this->conversationDocuments);
        $feature = $this->resolveChatFeature($this->webSearchMode, $hasDocumentContext);

        $this->logLatency('job_start', 0, $requestId, [
            'conversation_id' => $this->conversationId,
        ]);

        if (! $this->conversationStillExists()) {
            return;
        }

        // Fetch both document IDs and filenames from the same filtered query so
        // only owned + ready documents are used in RAG. This prevents processing,
        // foreign, or stale documents from reaching the Python retrieval layer.
        $docContext = $orchestrator->getActiveDocumentContext($this->conversationDocuments);
        $documentFilenames = $docContext['filenames'];
        $documentIds = $docContext['ids'];
        $sourcePolicy = $orchestrator->getSourcePolicy($documentFilenames);
        $allowAutoRealtimeWeb = $orchestrator->shouldAllowAutoRealtimeWeb($documentFilenames);
        $documentContextError = $orchestrator->documentContextUnavailableMessage($docContext);

        // Jika stream sedang memegang claim untuk latest user message,
        // job jangan ikut menjadi runner paralel. Requeue sebagai fallback
        // tertunda agar tetap bisa recover bila stream gagal sebelum persist.
        if ($orchestrator->hasActiveStreamClaim($this->conversationId)) {
            // Stream claim aktif (intent atau active) — defer 30 detik.
            // Dengan tries=10 dan release(30), job punya ~300 detik coverage
            // untuk menunggu claim TTL (240 detik) stale sebelum fallback jalan.
            $this->release(30);

            return;
        }

        // Guard: jika stream sudah sukses menyimpan assistant message sebelum
        // job retry ini berjalan, tidak perlu memanggil AI lagi. Ini mencegah
        // double AI call pada happy path stream + job fallback.
        if ($orchestrator->assistantAlreadyAnswered($this->conversationId)) {
            return;
        }

        if ($documentContextError !== null) {
            if ($orchestrator->saveErrorMessage($this->conversationId, $documentContextError, $this->userId) !== null) {
                Conversation::query()
                    ->whereKey($this->conversationId)
                    ->where('user_id', $this->userId)
                    ->touch();
            }

            $usageEvents->failed(
                feature: $feature,
                userId: $this->userId,
                metadata: [
                    'conversation_id' => $this->conversationId,
                    'channel' => 'job_fallback',
                    'web_search_mode' => $this->webSearchMode,
                    'has_documents' => $hasDocumentContext,
                    'document_count' => count($documentIds),
                    'reason' => 'document_context_unavailable',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($jobStartedAt),
                errorCode: 'document_context_unavailable',
            );

            $this->logLatency('job_total', microtime(true) * 1000 - $jobStartMs, $requestId, [
                'conversation_id' => $this->conversationId,
                'outcome' => 'document_context_unavailable',
            ]);

            return;
        }

        $fullResponse = '';
        $streamBuffer = '';
        $sources = [];
        $modelMetadata = [];

        $pythonCallStart = microtime(true) * 1000;

        foreach (
            $aiService->sendChat(
                $this->history,
                $documentFilenames,
                (string) $this->userId,
                $this->webSearchMode,
                $sourcePolicy,
                $allowAutoRealtimeWeb,
                $documentIds,
                $requestId,
            ) as $chunk
        ) {
            [$chunk, $streamBuffer, $parsedModelName, $parsedSources] = $orchestrator->extractStreamMetadata(
                (string) $chunk,
                $streamBuffer
            );

            if ($parsedModelName !== null) {
                $modelMetadata = $usageEvents->modelMetadata($parsedModelName);
            }

            if (! empty($parsedSources)) {
                $sources = $parsedSources;
            }

            $chunk = $orchestrator->sanitizeAssistantOutput((string) $chunk);

            if ($chunk !== '') {
                $fullResponse .= $chunk;
            }
        }

        $pythonCallEnd = microtime(true) * 1000;
        $this->logLatency('python_call_end', $pythonCallEnd - $pythonCallStart, $requestId, [
            'conversation_id' => $this->conversationId,
            'response_len' => strlen($fullResponse),
        ]);

        // Detect sentinel prefix injected by AIService on network/service errors.
        if (str_starts_with($fullResponse, AIService::ERROR_SENTINEL)) {
            $errorContent = substr($fullResponse, strlen(AIService::ERROR_SENTINEL));
            $errorContent = trim($errorContent) !== '' ? trim($errorContent) : 'Maaf, ISTA AI gagal merespon. Silakan coba lagi.';

            if ($orchestrator->saveErrorMessage($this->conversationId, $errorContent, $this->userId) !== null) {
                Conversation::query()
                    ->whereKey($this->conversationId)
                    ->where('user_id', $this->userId)
                    ->touch();
            }

            $usageEvents->failed(
                feature: $feature,
                userId: $this->userId,
                metadata: [
                    'conversation_id' => $this->conversationId,
                    'channel' => 'job_fallback',
                    'web_search_mode' => $this->webSearchMode,
                    'has_documents' => $hasDocumentContext,
                    'document_count' => count($documentIds),
                    'reason' => 'error_sentinel',
                    ...$modelMetadata,
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($jobStartedAt),
                errorCode: 'error_sentinel',
            );

            $this->logLatency('job_total', microtime(true) * 1000 - $jobStartMs, $requestId, [
                'conversation_id' => $this->conversationId,
                'outcome' => 'error_sentinel',
            ]);

            return;
        }

        $cleanContent = $orchestrator->cleanResponseContent($fullResponse);

        if (! empty($sources)) {
            $cleanContent .= $orchestrator->sanitizeAndFormatSources($sources);
        }
        $knowledgeMetadata = $orchestrator->knowledgeMetadataFromSources($sources);

        if ($cleanContent === '') {
            $cleanContent = 'Maaf, ISTA AI belum menerima jawaban yang bisa ditampilkan. Silakan coba lagi.';
        }

        $dbSaveStart = microtime(true) * 1000;
        $saved = $orchestrator->saveAssistantMessage($this->conversationId, $cleanContent, $this->userId);
        $this->logLatency('db_save', microtime(true) * 1000 - $dbSaveStart, $requestId, [
            'conversation_id' => $this->conversationId,
            'saved' => $saved !== null,
        ]);

        if ($saved !== null) {
            Conversation::query()
                ->whereKey($this->conversationId)
                ->where('user_id', $this->userId)
                ->touch();

            $usageEvents->completed(
                feature: $feature,
                userId: $this->userId,
                metadata: [
                    'conversation_id' => $this->conversationId,
                    'message_id' => (int) $saved->id,
                    'channel' => 'job_fallback',
                    'web_search_mode' => $this->webSearchMode,
                    'has_documents' => $hasDocumentContext,
                    'document_count' => count($documentIds),
                    'sources_count' => count($sources),
                    'has_sources' => ! empty($sources),
                    ...$knowledgeMetadata,
                    'response_length' => strlen($cleanContent),
                    ...$modelMetadata,
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($jobStartedAt),
                subject: $saved,
            );
        }

        $this->logLatency('job_total', microtime(true) * 1000 - $jobStartMs, $requestId, [
            'conversation_id' => $this->conversationId,
            'outcome' => 'success',
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Auth::loginUsingId($this->userId);
        $orchestrator = app(ChatOrchestrationService::class);
        $usageEvents = app(AIUsageEventService::class);

        Log::error('Background chat response failed', [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'message' => $exception?->getMessage(),
        ]);

        $hasDocumentContext = ! empty($this->conversationDocuments);
        $feature = $this->resolveChatFeature($this->webSearchMode, $hasDocumentContext);

        $usageEvents->failed(
            feature: $feature,
            userId: $this->userId,
            metadata: [
                'conversation_id' => $this->conversationId,
                'channel' => 'job_fallback',
                'web_search_mode' => $this->webSearchMode,
                'has_documents' => $hasDocumentContext,
                'document_count' => count($this->conversationDocuments),
                'reason' => 'job_failed',
                'job_attempts' => method_exists($this, 'attempts') ? $this->attempts() : null,
            ],
            requestId: $this->requestId,
            errorCode: 'job_failed',
        );

        if (! $this->conversationStillExists()) {
            return;
        }

        $saved = $orchestrator->saveErrorMessage(
            $this->conversationId,
            'Maaf, jawaban gagal diproses. Silakan coba kirim ulang.',
            $this->userId,
        );

        if ($saved !== null) {
            Conversation::query()
                ->whereKey($this->conversationId)
                ->where('user_id', $this->userId)
                ->touch();
        }
    }

    private function resolveChatFeature(bool $webSearchMode, bool $hasDocumentContext): string
    {
        if ($hasDocumentContext) {
            return AIUsageEvent::FEATURE_DOCUMENT_RAG;
        }

        if ($webSearchMode) {
            return AIUsageEvent::FEATURE_WEB_SEARCH;
        }

        return AIUsageEvent::FEATURE_CHAT;
    }

    private function conversationStillExists(): bool
    {
        return Conversation::query()
            ->whereKey($this->conversationId)
            ->where('user_id', $this->userId)
            ->exists();
    }

    /**
     * Emit a structured latency log line.
     * Only logs metadata — never logs query content, document content, or secrets.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logLatency(string $stage, float $durationMs, string $requestId, array $extra = []): void
    {
        $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::info(sprintf(
            '[LATENCY] stage=%s request_id=%s duration_ms=%.1f extra=%s',
            $stage,
            $requestId,
            $durationMs,
            $extraJson,
        ));
    }
}
