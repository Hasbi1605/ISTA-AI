<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;

class ChatOrchestrationService
{
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

    public function buildHistory(array $messages): array
    {
        return array_map(
            fn (array $msg) => [
                'role' => (string) ($msg['role'] ?? ''),
                'content' => (string) ($msg['content'] ?? ''),
            ],
            $messages
        );
    }

    public function getDocumentFilenames(array $conversationDocuments): ?array
    {
        if (empty($conversationDocuments)) {
            return null;
        }

        return Document::whereIn('id', $conversationDocuments)
            ->pluck('original_name')
            ->toArray();
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

    public function saveAssistantMessage(int $conversationId, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $content
        ]);
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

        if (preg_match('/\[SOURCES:(\[.+?\])\]/s', $combined, $matches)) {
            $parsedSources = json_decode($matches[1], true);
            if (is_array($parsedSources)) {
                $sources = $parsedSources;
            }
            $combined = preg_replace('/\[SOURCES:\[.+?\]\]/s', '', $combined, 1) ?? $combined;
        }

        $nextBuffer = '';
        foreach (['[SOURCES:', '[MODEL:'] as $marker) {
            $markerPos = strrpos($combined, $marker);
            if ($markerPos === false) {
                continue;
            }

            $tail = substr($combined, $markerPos);
            $isComplete = $marker === '[SOURCES:'
                ? preg_match('/^\[SOURCES:(\[.+?\])\]/s', $tail) === 1
                : preg_match('/^\[MODEL:(.+?)\]\n?/s', $tail) === 1;

            if (!$isComplete) {
                $nextBuffer = $tail;
                $combined = substr($combined, 0, $markerPos);
                break;
            }
        }

        return [$combined, $nextBuffer, $modelName, $sources];
    }
}
