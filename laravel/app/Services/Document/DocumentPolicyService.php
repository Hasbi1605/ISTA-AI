<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\Log;
use App\Services\Chat\LaravelChatService;

class DocumentPolicyService
{
    private const EXPLICIT_WEB_PATTERNS = [
        '/\bcari\s+di\s+web\b/i',
        '/\bweb\s+search\b/i',
        '/\bbrowse\s+web\b/i',
        '/\bsearch\s+online\b/i',
        '/\bpakai\s+(internet|web)\b/i',
        '/\btolong\s+cari\s+di\s+internet\b/i',
    ];

    private const REALTIME_HIGH_PATTERNS = [
        '/\bsekarang\b/i',
        '/\bhari\s+ini\b/i',
        '/\bterbaru\b/i',
        '/\bterkini\b/i',
        '/\bupdate\b/i',
    ];

    private const REALTIME_MEDIUM_KEYWORDS = [
        'update', 'terbaru', 'terkini', 'berita', 'cuaca', 'jadwal',
    ];

    public function shouldUseWebSearch(
        string $query,
        bool $forceWebSearch = false,
        bool $explicitWebRequest = false,
        bool $allowAutoRealtimeWeb = true,
        bool $documentsActive = false
    ): array {
        $realtimeIntent = $this->detectRealtimeIntentLevel($query);
        $explicitDetected = $explicitWebRequest || $this->detectExplicitWebRequest($query);

        if ($forceWebSearch) {
            return [
                'should_search' => true,
                'reason_code' => $documentsActive ? 'DOC_WEB_TOGGLE' : 'WEB_TOGGLE',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($explicitDetected) {
            return [
                'should_search' => true,
                'reason_code' => $documentsActive ? 'DOC_WEB_EXPLICIT' : 'EXPLICIT_WEB',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($documentsActive) {
            return [
                'should_search' => false,
                'reason_code' => 'DOC_NO_WEB',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($allowAutoRealtimeWeb) {
            if ($realtimeIntent === 'high') {
                return [
                    'should_search' => true,
                    'reason_code' => 'REALTIME_AUTO_HIGH',
                    'realtime_intent' => $realtimeIntent,
                ];
            }
            if ($realtimeIntent === 'medium') {
                return [
                    'should_search' => true,
                    'reason_code' => 'REALTIME_AUTO_MEDIUM',
                    'realtime_intent' => $realtimeIntent,
                ];
            }
        }

        return [
            'should_search' => false,
            'reason_code' => 'NO_WEB',
            'realtime_intent' => $realtimeIntent,
        ];
    }

    public function detectExplicitWebRequest(string $query): bool
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return false;
        }

        foreach (self::EXPLICIT_WEB_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public function detectRealtimeIntentLevel(string $query): string
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return 'low';
        }

        foreach (self::REALTIME_HIGH_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return 'high';
            }
        }

        $hits = 0;
        foreach (self::REALTIME_MEDIUM_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                $hits++;
            }
        }

        if ($hits >= 2) {
            return 'medium';
        }
        if ($hits === 1 && str_word_count($normalized) <= 4) {
            return 'medium';
        }

        return 'low';
    }

    public function getNoAnswerPrompt(): string
    {
        if (app()->bound('config')) {
            return config(
                'ai.prompts.no_answer',
                'Saya belum menemukan informasi tersebut pada dokumen yang sedang aktif. '
                . 'Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.'
            );
        }

        return 'Saya belum menemukan informasi tersebut pada dokumen yang sedang aktif. '
            . 'Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.';
    }

    public function getDocumentErrorPrompt(): string
    {
        if (app()->bound('config')) {
            return config(
                'ai.prompts.document_error',
                'Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini. '
                . 'Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.'
            );
        }

        return 'Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini. '
            . 'Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.';
    }
}