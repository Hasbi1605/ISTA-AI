<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class ChatOrchestrationService
{
    private const STREAM_CLAIM_TTL_SECONDS = 240;
    private const SANITIZE_REPLACEMENTS = [
        '/\bchunks?\b/i' => 'bagian dokumen',
        '/\bchunk(?:ing|ed)?\b/i' => 'bagian dokumen',
        '/\bembeddings?\b/i' => 'representasi dokumen',
        '/\bvectors?\b/i' => 'indeks dokumen',
        '/\brag\b/i' => 'konteks dokumen',
        '/\bretrieval\b/i' => 'pencarian dokumen',
        '/\btop\s*[- ]?k\b/i' => 'hasil teratas',
    ];

    public function createConversationIfNeeded(?int $currentConversationId, string $prompt): int
    {
        if (!$currentConversationId) {
            $conversation = Conversation::create([
                'user_id' => Auth::id(),
                'title' => substr($prompt, 0, 50) . '...'
            ]);
            return $conversation->id;
        }

        $ownedConversationExists = Conversation::where('id', $currentConversationId)
            ->where('user_id', Auth::id())
            ->exists();

        if (! $ownedConversationExists) {
            throw new AuthorizationException('Unauthorized conversation access.');
        }

        return $currentConversationId;
    }

    public function saveUserMessage(int $conversationId, string $prompt): array
    {
        $userMessage = Message::create([
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $prompt
        ]);

        return $userMessage->toArray();
    }

    /**
     * Build the message history payload for the AI service.
     *
     * Applies a sliding window to prevent context-window overflow and
     * unbounded token costs on long conversations. The window size is
     * configurable via services.ai_service.max_history_messages (default 20).
     * Only the most recent N messages are sent; older messages are dropped.
     *
     * After slicing, any leading assistant messages are dropped so the
     * payload always starts with a user message — required by providers
     * that enforce strict user/assistant turn ordering (e.g. Bedrock Converse).
     */
    public function buildHistory(array $messages): array
    {
        $maxMessages = $this->maxHistoryMessages();

        // Keep only the most recent N messages (sliding window)
        if (count($messages) > $maxMessages) {
            $messages = array_slice($messages, -$maxMessages);
        }

        // Drop leading assistant messages so the payload always starts
        // with a user turn. This can happen when the window boundary
        // falls in the middle of a user/assistant pair.
        while (! empty($messages) && ($messages[0]['role'] ?? '') === 'assistant') {
            array_shift($messages);
        }

        return array_map(
            fn (array $msg) => [
                'role' => (string) ($msg['role'] ?? ''),
                'content' => (string) ($msg['content'] ?? ''),
            ],
            $messages
        );
    }

    protected function maxHistoryMessages(): int
    {
        try {
            $raw = config('services.ai_service.max_history_messages', 20);

            return max(1, (int) $this->normalizeIntConfig($raw, 20));
        } catch (\Throwable) {
            return 20;
        }
    }

    /**
     * Normalize a config value that may arrive as a quoted string at runtime.
     * e.g. ' "20" ' → 20, '20' → 20, 20 → 20.
     */
    private function normalizeIntConfig(mixed $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }

        $normalized = trim((string) $value);

        // Strip surrounding quotes (single or double)
        if (strlen($normalized) >= 2) {
            $quote = $normalized[0];
            if (($quote === '"' || $quote === "'") && $normalized[strlen($normalized) - 1] === $quote) {
                $normalized = substr($normalized, 1, -1);
            }
        }

        return is_numeric($normalized) ? (int) $normalized : $default;
    }

    public function getDocumentFilenames(array $conversationDocuments): ?array
    {
        if (empty($conversationDocuments)) {
            return null;
        }

        $filenames = Document::whereIn('id', $conversationDocuments)
            ->where('user_id', Auth::id())
            ->where('status', 'ready')
            ->pluck('original_name')
            ->toArray();

        return empty($filenames) ? null : $filenames;
    }

    /**
     * Return both the filtered document IDs and filenames from a single query.
     *
     * Only documents that are owned by the authenticated user AND have status
     * "ready" are included. This ensures `document_ids` sent to Python always
     * match the `document_filenames` array — both come from the same source.
     *
     * @param  array<int|string>  $conversationDocuments  Raw document IDs from the conversation.
     * @return array{ids: list<int>, filenames: list<string>|null}
     */
    public function getActiveDocumentContext(array $conversationDocuments): array
    {
        if (empty($conversationDocuments)) {
            return ['ids' => [], 'filenames' => null];
        }

        $docs = Document::whereIn('id', $conversationDocuments)
            ->where('user_id', Auth::id())
            ->where('status', 'ready')
            ->get(['id', 'original_name']);

        $ids = $docs->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $filenames = $docs->pluck('original_name')->values()->all();

        return [
            'ids' => $ids,
            'filenames' => empty($filenames) ? null : $filenames,
        ];
    }

    public function getSourcePolicy(?array $documentFilenames): string
    {
        return !empty($documentFilenames) ? 'document_context' : 'hybrid_realtime_auto';
    }

    public function shouldAllowAutoRealtimeWeb(?array $documentFilenames): bool
    {
        return empty($documentFilenames);
    }

    public function sanitizeAndFormatSources(array $sources): string
    {
        if (empty($sources)) {
            return '';
        }

        $normalizedSources = $this->normalizeSources($sources);
        if (empty($normalizedSources)) {
            return '';
        }

        $webSources = [];
        $documentSources = [];

        foreach ($normalizedSources as $source) {
            if (!empty($source['url'])) {
                $webSources[] = $source;
                continue;
            }

            if (!empty($source['filename'])) {
                $documentSources[] = $source['filename'];
            }
        }

        $documentSources = array_values(array_unique($documentSources));

        if (count($webSources) === 0 && count($documentSources) === 1) {
            return "\n\n---\nDokumen rujukan: **{$documentSources[0]}**";
        }

        $lines = ["", "", "---", "**Rujukan:**"];

        foreach ($webSources as $source) {
            $title = !empty($source['title']) ? $source['title'] : parse_url($source['url'], PHP_URL_HOST);
            $lines[] = "- [{$title}]({$source['url']})";
        }

        foreach ($documentSources as $filename) {
            $lines[] = "- Dokumen: {$filename}";
        }

        return implode("\n", $lines);
    }

    private function normalizeSources(array $sources): array
    {
        $normalized = [];
        $seen = [];

        foreach ($sources as $source) {
            $url = trim((string) ($source['url'] ?? ''));
            $filename = trim((string) ($source['filename'] ?? ''));

            if ($url === '' && $filename === '') {
                continue;
            }

            $key = $url !== '' ? "web:{$url}" : "doc:{$filename}";
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $source;
        }

        return $normalized;
    }

    public function sanitizeAssistantOutput(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $sanitized = $text;
        foreach (self::SANITIZE_REPLACEMENTS as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, (string) $sanitized);
        }

        return (string) $sanitized;
    }

    public function cleanResponseContent(string $fullResponse): string
    {
        $cleanContent = preg_replace('/\[SOURCES:\[.+?\]\]/s', '', $fullResponse);
        $cleanContent = $this->sanitizeAssistantOutput((string) $cleanContent);
        return trim($cleanContent);
    }

    /**
     * Check whether an assistant message (normal or error) already exists after
     * the latest user message in this conversation.
     *
     * Used by ChatStreamController as a single-runner claim: if the background
     * job already answered, the stream should skip calling AI entirely to avoid
     * sending different chunks to the UI than what ends up in the DB.
     */
    public function assistantAlreadyAnswered(int $conversationId): bool
    {
        $latestUserMessage = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if ($latestUserMessage === null) {
            return false;
        }

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('id', '>', $latestUserMessage->id)
            ->exists();
    }

    public function streamClaimKeyForLatestUserMessage(int $conversationId): ?string
    {
        $latestUserMessage = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if ($latestUserMessage === null) {
            return null;
        }

        return sprintf('chat:stream-claim:conversation:%d:user-message:%d', $conversationId, (int) $latestUserMessage->id);
    }

    public function createStreamIntent(int $conversationId): ?string
    {
        $claimKey = $this->streamClaimKeyForLatestUserMessage($conversationId);
        if ($claimKey === null) {
            return null;
        }

        Cache::add($claimKey, 'intent', now()->addSeconds(self::STREAM_CLAIM_TTL_SECONDS));

        return $claimKey;
    }

    /**
     * Atomically acquire the single stream runner slot for the latest user
     * message. The stream can either adopt a sendMessage intent or create an
     * active claim directly when a stream request reaches the server first.
     */
    public function acquireStreamRunner(int $conversationId): ?string
    {
        $claimKey = $this->streamClaimKeyForLatestUserMessage($conversationId);
        if ($claimKey === null) {
            return null;
        }

        $lock = Cache::lock($claimKey.':lock', 5);

        if (! $lock->get()) {
            return null;
        }

        try {
            $current = Cache::get($claimKey);

            if ($current === 'active') {
                return null;
            }

            if ($current === null || $current === 'intent') {
                Cache::put($claimKey, 'active', now()->addSeconds(self::STREAM_CLAIM_TTL_SECONDS));

                return $claimKey;
            }

            return null;
        } finally {
            $lock->release();
        }
    }

    public function releaseStreamClaim(?string $claimKey): void
    {
        if ($claimKey === null) {
            return;
        }

        Cache::forget($claimKey);
    }

    /**
     * Returns true if a stream claim (intent or active) exists for the latest
     * user message. The fallback job defers in both states.
     */
    public function hasActiveStreamClaim(int $conversationId): bool
    {
        $claimKey = $this->streamClaimKeyForLatestUserMessage($conversationId);
        if ($claimKey === null) {
            return false;
        }

        $value = Cache::get($claimKey);
        return $value === 'intent' || $value === 'active';
    }

    public function saveAssistantMessage(int $conversationId, string $content, int $userId): ?Message
    {
        if (! $this->conversationExists($conversationId, $userId)) {
            return null;
        }

        try {
            // Idempotensi: gunakan DB transaction + lockForUpdate pada user message
            // terakhir agar baik SSE stream maupun background job tidak bisa
            // menyimpan dua assistant message untuk satu user message yang sama.
            // Ini adalah single source of truth untuk race condition di kedua jalur.
            return \Illuminate\Support\Facades\DB::transaction(function () use ($conversationId, $content) {
                $latestUserMessage = \App\Models\Message::query()
                    ->where('conversation_id', $conversationId)
                    ->where('role', 'user')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                // Jika sudah ada assistant message setelah user message terakhir:
                // - jika sukses (is_error=false): skip (idempotent)
                // - jika error (is_error=true): upgrade menjadi jawaban sukses
                if ($latestUserMessage !== null) {
                    $latestAssistant = \App\Models\Message::query()
                        ->where('conversation_id', $conversationId)
                        ->where('role', 'assistant')
                        ->where('id', '>', $latestUserMessage->id)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    if ($latestAssistant !== null) {
                        if ((bool) $latestAssistant->is_error === false) {
                            return null;
                        }

                        // Recovery path: stream error duluan lalu job sukses belakangan.
                        // Reuse row error yang ada agar tetap satu assistant message.
                        $latestAssistant->forceFill([
                            'content' => $content,
                            'is_error' => false,
                        ])->save();

                        return $latestAssistant->fresh();
                    }
                }

                return $this->createAssistantMessage($conversationId, $content);
            });
        } catch (QueryException $e) {
            if ($this->isConversationFkViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    public function saveErrorMessage(int $conversationId, string $content, int $userId): ?Message
    {
        if (! $this->conversationExists($conversationId, $userId)) {
            return null;
        }

        try {
            // Idempotensi: gunakan DB transaction + lockForUpdate agar error message
            // tidak duplikat jika stream dan job keduanya gagal bersamaan.
            // Jika sudah ada assistant message (normal atau error) setelah user message
            // terakhir, skip — ini mencegah error stream menimpa jawaban sukses dari job.
            return \Illuminate\Support\Facades\DB::transaction(function () use ($conversationId, $content) {
                $latestUserMessage = \App\Models\Message::query()
                    ->where('conversation_id', $conversationId)
                    ->where('role', 'user')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($latestUserMessage !== null) {
                    $latestAssistant = \App\Models\Message::query()
                        ->where('conversation_id', $conversationId)
                        ->where('role', 'assistant')
                        ->where('id', '>', $latestUserMessage->id)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    // Jika sudah ada jawaban sukses, jangan ditimpa error.
                    // Jika sudah ada error, tetap idempotent (skip).
                    if ($latestAssistant !== null) {
                        return null;
                    }
                }

                return Message::create([
                    'conversation_id' => $conversationId,
                    'role' => 'assistant',
                    'content' => $content,
                    'is_error' => true,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isConversationFkViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    protected function conversationExists(int $conversationId, int $userId): bool
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $userId)
            ->exists();
    }

    protected function createAssistantMessage(int $conversationId, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $content,
        ]);
    }

    protected function isConversationFkViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $errorCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' && (int) $errorCode === 1452;
    }

    public function extractStreamMetadata(string $chunk, string $buffer = ''): array
    {
        $combined = $buffer . $chunk;
        $modelName = null;
        $sources = null;

        if (preg_match('/\[MODEL:(.+?)\]\n?/', $combined, $matches)) {
            $modelName = trim((string) $matches[1]);
            $combined = preg_replace('/\[MODEL:.+?\]\n?/', '', $combined, 1) ?? $combined;
        }

        [$combined, $sourcesBuffer, $parsedSources] = $this->extractSourcesMetadata($combined);
        if (is_array($parsedSources)) {
            $sources = $parsedSources;
        }

        if ($sourcesBuffer !== '') {
            return [$combined, $sourcesBuffer, $modelName, $sources];
        }

        $nextBuffer = '';
        foreach (['[MODEL:'] as $marker) {
            $markerPos = strrpos($combined, $marker);
            if ($markerPos === false) {
                continue;
            }

            $tail = substr($combined, $markerPos);
            $isComplete = preg_match('/^\[MODEL:(.+?)\]\n?/s', $tail) === 1;

            if (!$isComplete) {
                $nextBuffer = $tail;
                $combined = substr($combined, 0, $markerPos);
                break;
            }
        }

        return [$combined, $nextBuffer, $modelName, $sources];
    }

    private function extractSourcesMetadata(string $combined): array
    {
        $sources = null;
        $searchOffset = 0;
        $marker = '[SOURCES:';
        $markerLength = strlen($marker);

        while (($markerPos = strpos($combined, $marker, $searchOffset)) !== false) {
            $jsonStart = $markerPos + $markerLength;

            if (strlen($combined) <= $jsonStart) {
                return [
                    substr($combined, 0, $markerPos),
                    substr($combined, $markerPos),
                    $sources,
                ];
            }

            if ($combined[$jsonStart] !== '[') {
                $searchOffset = $jsonStart;
                continue;
            }

            $jsonEnd = $this->findJsonArrayEnd($combined, $jsonStart);
            if ($jsonEnd === null || strlen($combined) <= $jsonEnd + 1) {
                return [
                    substr($combined, 0, $markerPos),
                    substr($combined, $markerPos),
                    $sources,
                ];
            }

            if ($combined[$jsonEnd + 1] !== ']') {
                $searchOffset = $jsonEnd + 1;
                continue;
            }

            $json = substr($combined, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsedSources = json_decode($json, true);
            if (is_array($parsedSources)) {
                $sources = $parsedSources;
            }

            $combined = substr($combined, 0, $markerPos).substr($combined, $jsonEnd + 2);
            $searchOffset = $markerPos;
        }

        return [$combined, '', $sources];
    }

    private function findJsonArrayEnd(string $text, int $start): ?int
    {
        if (($text[$start] ?? null) !== '[') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '[') {
                $depth++;
                continue;
            }

            if ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
