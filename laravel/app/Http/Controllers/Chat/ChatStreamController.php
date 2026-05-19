<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use App\Services\AI\AIConfigurationResolver;
use App\Services\AIService;
use App\Services\ChatOrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function stream(Request $request, int $conversationId): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Validate conversation ownership
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->first();

        if ($conversation === null) {
            abort(404);
        }

        // Parse only document IDs and web search mode from query string.
        // History is reconstructed server-side from DB to avoid:
        //   - URL length limits (414) with long conversations
        //   - Chat content leaking into access logs / proxy logs
        //   - Arbitrary history injection from client
        $requestedDocumentIds = $this->parseDocumentIds($request->input('document_ids', '[]'));
        $documentIds = $this->documentIdsForLatestUserMessage($conversationId, $requestedDocumentIds);
        $webSearchMode = filter_var($request->input('web_search_mode', false), FILTER_VALIDATE_BOOLEAN);
        $clientRequestId = $this->normalizeRequestId($request->input('request_id'));

        $aiService = app(AIService::class);
        $orchestrator = app(ChatOrchestrationService::class);

        // Reconstruct history server-side from DB messages
        $dbMessages = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'asc')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => (string) $m->role, 'content' => (string) $m->content])
            ->all();

        $history = $orchestrator->buildHistory($dbMessages);

        // Resolve document context (owned + ready only) — must run before closure
        // so Auth::id() is still set in the request context.
        $docContext = $orchestrator->getActiveDocumentContext($documentIds);
        $documentFilenames = $docContext['filenames'];
        $resolvedDocumentIds = $docContext['ids'];
        $sourcePolicy = $orchestrator->getSourcePolicy($documentFilenames);
        $allowAutoRealtimeWeb = $orchestrator->shouldAllowAutoRealtimeWeb($documentFilenames);
        $documentContextError = $orchestrator->documentContextUnavailableMessage($docContext);
        $documentContextWarning = $orchestrator->documentContextPartialWarning($docContext);

        return new StreamedResponse(function () use (
            $aiService,
            $orchestrator,
            $history,
            $documentFilenames,
            $resolvedDocumentIds,
            $sourcePolicy,
            $allowAutoRealtimeWeb,
            $documentContextError,
            $documentContextWarning,
            $webSearchMode,
            $conversationId,
            $conversation,
            $user,
            $clientRequestId,
        ) {
            // Disable output buffering so chunks reach the browser immediately
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            set_time_limit(180);

            // Flush all output buffer levels (handles PHP-FPM multi-level buffers)
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $this->executeStream(
                $aiService,
                $orchestrator,
                $history,
                $documentFilenames,
                $resolvedDocumentIds,
                $sourcePolicy,
                $allowAutoRealtimeWeb,
                $webSearchMode,
                $conversationId,
                $conversation,
                $user,
                $documentContextError,
                $documentContextWarning,
                $clientRequestId,
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Core streaming logic — extracted so it can be called directly in tests
     * without needing to execute a StreamedResponse closure.
     */
    public function executeStream(
        AIService $aiService,
        ChatOrchestrationService $orchestrator,
        array $history,
        ?array $documentFilenames,
        array $resolvedDocumentIds,
        string $sourcePolicy,
        bool $allowAutoRealtimeWeb,
        bool $webSearchMode,
        int $conversationId,
        Conversation $conversation,
        User $user,
        ?string $documentContextError = null,
        ?string $documentContextWarning = null,
        ?string $clientRequestId = null,
    ): void {
        $usageEvents = app(AIUsageEventService::class);
        $streamStartedAt = microtime(true);
        $hasDocumentContext = ! empty($resolvedDocumentIds) || ! empty($documentFilenames);
        $feature = $this->resolveChatFeature($webSearchMode, $hasDocumentContext);
        $configMetadata = app(AIConfigurationResolver::class)->usageMetadataForFeature($feature);
        $requestId = $clientRequestId ?? $usageEvents->newRequestId();

        $streamClaimKey = $orchestrator->acquireStreamRunner($conversationId);
        if ($streamClaimKey === null) {
            // Runner lain (job/stream lain) sudah claim latest user message.
            $this->sendSseEvent('done', '1');

            return;
        }

        try {
            // Single-runner claim: jika assistant message sudah ada untuk user message
            // terakhir (job selesai duluan), stream tidak perlu memanggil AI sama sekali.
            // Ini mencegah user melihat chunk dari jawaban berbeda lalu final DB berubah.
            if ($orchestrator->assistantAlreadyAnswered($conversationId)) {
                $this->sendSseEvent('done', '1');

                return;
            }

            if ($documentContextError !== null) {
                $saved = $orchestrator->saveErrorMessage($conversationId, $documentContextError, $user->id);
                if ($saved !== null) {
                    $conversation->touch();
                }

                $usageEvents->failed(
                    feature: $feature,
                    userId: (int) $user->id,
                    metadata: [
                        'conversation_id' => $conversationId,
                        'channel' => 'stream',
                        'web_search_mode' => $webSearchMode,
                        'has_documents' => $hasDocumentContext,
                        'document_count' => count($resolvedDocumentIds),
                        'reason' => 'document_context_unavailable',
                        ...$configMetadata,
                    ],
                    requestId: $requestId,
                    latencyMs: $usageEvents->latencyMsSince($streamStartedAt),
                    errorCode: 'document_context_unavailable',
                );

                $this->sendSseEvent('error', $documentContextError);
                $this->sendSseEvent('done', '1');

                return;
            }

            if ($documentContextWarning !== null) {
                $this->sendSseEvent('document-warning', $documentContextWarning);
            }

            $fullResponse = '';
            $streamBuffer = '';
            $sources = [];
            $errorStreamDetected = false;
            $modelMetadata = [];

            try {
                foreach (
                    $aiService->sendChat(
                        $history,
                        $documentFilenames,
                        (string) $user->id,
                        $webSearchMode,
                        $sourcePolicy,
                        $allowAutoRealtimeWeb,
                        $resolvedDocumentIds,
                        $requestId,
                    ) as $rawChunk
                ) {
                    // Abort if browser disconnected
                    if (connection_aborted()) {
                        return;
                    }

                    [$chunk, $streamBuffer, $parsedModelName, $parsedSources] = $orchestrator->extractStreamMetadata(
                        (string) $rawChunk,
                        $streamBuffer
                    );

                    if ($parsedModelName !== null) {
                        $modelMetadata = $usageEvents->modelMetadata($parsedModelName);
                        $this->sendSseEvent('model-name', $parsedModelName);
                    }

                    if (! empty($parsedSources)) {
                        $sources = $parsedSources;
                        $this->sendSseEvent('sources', json_encode($sources));
                    }

                    if ($fullResponse === '' && str_starts_with((string) $chunk, AIService::ERROR_SENTINEL)) {
                        $errorStreamDetected = true;
                    }

                    if ($errorStreamDetected) {
                        $fullResponse .= (string) $chunk;

                        continue;
                    }

                    $chunk = $orchestrator->sanitizeAssistantOutput((string) $chunk);

                    if ($chunk !== '') {
                        $fullResponse .= $chunk;
                        $this->sendSseEvent('chunk', $chunk);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('ChatStreamController: stream error', [
                    'conversation_id' => $conversationId,
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                $usageEvents->failed(
                    feature: $feature,
                    userId: (int) $user->id,
                    metadata: [
                        'conversation_id' => $conversationId,
                        'channel' => 'stream',
                        'web_search_mode' => $webSearchMode,
                        'has_documents' => $hasDocumentContext,
                        'document_count' => count($resolvedDocumentIds),
                        'reason' => 'stream_exception',
                        ...$configMetadata,
                        ...$modelMetadata,
                    ],
                    requestId: $requestId,
                    latencyMs: $usageEvents->latencyMsSince($streamStartedAt),
                    errorCode: 'stream_exception',
                );

                $this->sendSseEvent('error', 'Maaf, terjadi kesalahan saat streaming jawaban.');
                $this->sendSseEvent('done', '1');

                return;
            }

            // Detect error sentinel from AIService
            if (str_starts_with($fullResponse, AIService::ERROR_SENTINEL)) {
                $errorContent = substr($fullResponse, strlen(AIService::ERROR_SENTINEL));
                $errorContent = trim($errorContent) !== '' ? trim($errorContent) : 'Maaf, ISTA AI gagal merespon. Silakan coba lagi.';

                $orchestrator->saveErrorMessage($conversationId, $errorContent, $user->id);
                $conversation->touch();

                $usageEvents->failed(
                    feature: $feature,
                    userId: (int) $user->id,
                    metadata: [
                        'conversation_id' => $conversationId,
                        'channel' => 'stream',
                        'web_search_mode' => $webSearchMode,
                        'has_documents' => $hasDocumentContext,
                        'document_count' => count($resolvedDocumentIds),
                        'reason' => 'error_sentinel',
                        ...$configMetadata,
                        ...$modelMetadata,
                    ],
                    requestId: $requestId,
                    latencyMs: $usageEvents->latencyMsSince($streamStartedAt),
                    errorCode: 'error_sentinel',
                );

                $this->sendSseEvent('error', $errorContent);
                $this->sendSseEvent('done', '1');

                return;
            }

            // Build final content with sources
            $cleanContent = $orchestrator->cleanResponseContent($fullResponse);

            if (! empty($sources)) {
                $cleanContent .= $orchestrator->sanitizeAndFormatSources($sources);
            }
            $knowledgeMetadata = $orchestrator->knowledgeMetadataFromSources($sources);

            if ($cleanContent === '') {
                $cleanContent = 'Maaf, ISTA AI belum menerima jawaban yang bisa ditampilkan. Silakan coba lagi.';
            }

            $this->sendSseEvent('final-content', $cleanContent);

            // Persist final message via saveAssistantMessage which now enforces
            // idempotency under DB lockForUpdate — safe against race with background job.
            $saved = $orchestrator->saveAssistantMessage($conversationId, $cleanContent, $user->id);
            if ($saved !== null) {
                $conversation->touch();
                $this->sendSseEvent('message-id', (string) $saved->id);
                $this->sendSseEvent('message-created-at', $saved->created_at?->timezone('Asia/Jakarta')->toIso8601String() ?? now('Asia/Jakarta')->toIso8601String());

                $usageEvents->completed(
                    feature: $feature,
                    userId: (int) $user->id,
                    metadata: [
                        'conversation_id' => $conversationId,
                        'message_id' => (int) $saved->id,
                        'channel' => 'stream',
                        'web_search_mode' => $webSearchMode,
                        'has_documents' => $hasDocumentContext,
                        'document_count' => count($resolvedDocumentIds),
                        'sources_count' => count($sources),
                        'has_sources' => ! empty($sources),
                        ...$knowledgeMetadata,
                        ...$configMetadata,
                        'response_length' => strlen($cleanContent),
                        ...$modelMetadata,
                    ],
                    requestId: $requestId,
                    latencyMs: $usageEvents->latencyMsSince($streamStartedAt),
                    subject: $saved,
                );
            }

            $this->sendSseEvent('done', '1');
        } finally {
            $orchestrator->releaseStreamClaim($streamClaimKey);
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

    /**
     * Send a single SSE event to the browser using multi-line SSE framing.
     * Each line of data is sent as a separate "data:" line so the browser
     * automatically joins them with newlines — no lossy escape/unescape needed.
     */
    private function sendSseEvent(string $event, string $data): void
    {
        echo "event: {$event}\n";
        // Split on newlines and emit each as a separate data: line.
        // The SSE spec says the browser joins multiple data: lines with \n.
        foreach (explode("\n", str_replace("\r\n", "\n", $data)) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
        flush();
    }

    /**
     * Validate the optional client-supplied request id so that stream lifecycle
     * events can be correlated with the `started` event recorded by Livewire.
     * Falls back to null when the value is missing or malformed; the caller
     * generates a fresh UUID in that case so logging keeps working.
     */
    private function normalizeRequestId(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Parse JSON document IDs from query string.
     *
     * @return array<int, int>
     */
    private function parseDocumentIds(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return [];
            }

            return app(ChatOrchestrationService::class)->normalizeDocumentIds($decoded);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Prefer document IDs persisted with the latest user message so a tampered
     * EventSource query string cannot silently swap the RAG context.
     *
     * @param  array<int, int>  $fallbackDocumentIds
     * @return array<int, int>
     */
    private function documentIdsForLatestUserMessage(int $conversationId, array $fallbackDocumentIds): array
    {
        $message = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first(['document_ids']);

        if ($message === null || $message->document_ids === null) {
            return $fallbackDocumentIds;
        }

        return app(ChatOrchestrationService::class)->normalizeDocumentIds($message->document_ids);
    }
}
