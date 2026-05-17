<?php

namespace Tests\Unit\Services;

use App\Services\ChatOrchestrationService;
use PHPUnit\Framework\TestCase;

class ChatOrchestrationServiceTest extends TestCase
{
    public function test_build_history_preserves_messages_without_injecting_system_prompt(): void
    {
        $service = new ChatOrchestrationService();

        $messages = [
            ['role' => 'user', 'content' => 'Tolong ringkas agenda hari ini'],
            ['role' => 'assistant', 'content' => 'Berikut ringkasannya.'],
        ];

        $history = $service->buildHistory($messages);

        $this->assertSame($messages, $history);
        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('assistant', $history[1]['role']);
    }

    public function test_build_history_strips_database_fields_before_sending_to_ai(): void
    {
        $service = new ChatOrchestrationService();

        $messages = [
            [
                'id' => 10,
                'conversation_id' => 5,
                'role' => 'user',
                'content' => 'Tolong siapkan ringkasan',
                'metadata' => ['trace_id' => 'abc-123'],
                'timestamp' => '2026-04-22T10:00:00+07:00',
            ],
        ];

        $history = $service->buildHistory($messages);

        $this->assertSame([
            [
                'role' => 'user',
                'content' => 'Tolong siapkan ringkasan',
            ],
        ], $history);
    }

    public function test_build_history_drops_leading_assistant_after_truncation(): void
    {
        // When window boundary falls mid-pair, result must not start with assistant
        $service = new class extends ChatOrchestrationService {
            protected function maxHistoryMessages(): int { return 4; }
        };

        // 6 messages: u1 a1 u2 a2 u3 a3
        // After slice(-4): a2 u3 a3 u4 — wait, let's build a clear case:
        // u1 a1 u2 a2 u3 a3 u4 a4 u5 a5 (10 messages)
        // slice(-4) = a4 u5 a5 u6... let's use explicit messages
        $messages = [
            ['role' => 'user',      'content' => 'u1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user',      'content' => 'u2'],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user',      'content' => 'u3'],
            ['role' => 'assistant', 'content' => 'a3'],
        ];

        // slice(-4) = [a2, u3, a3, u4] — but we only have 6 messages
        // slice(-4) = [u2, a2, u3, a3] — starts with user, no drop needed
        // Let's use 5 messages where slice(-4) starts with assistant:
        // u1 a1 u2 a2 u3 → slice(-4) = [a1, u2, a2, u3] — starts with assistant
        $messages = [
            ['role' => 'user',      'content' => 'u1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user',      'content' => 'u2'],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user',      'content' => 'u3'],
        ];

        $history = $service->buildHistory($messages);

        // slice(-4) = [a1, u2, a2, u3] → drop leading a1 → [u2, a2, u3]
        $this->assertSame('user', $history[0]['role'],
            'History must not start with an assistant message after truncation');
        $this->assertSame('u2', $history[0]['content']);
        $this->assertSame('u3', end($history)['content']);
    }

    public function test_build_history_does_not_drop_leading_user_message(): void
    {
        $service = new class extends ChatOrchestrationService {
            protected function maxHistoryMessages(): int { return 4; }
        };

        $messages = [
            ['role' => 'user',      'content' => 'u1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user',      'content' => 'u2'],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user',      'content' => 'u3'],
            ['role' => 'assistant', 'content' => 'a3'],
        ];

        // slice(-4) = [u2, a2, u3, a3] — starts with user, nothing dropped
        $history = $service->buildHistory($messages);

        $this->assertCount(4, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('u2', $history[0]['content']);
    }

    public function test_build_history_truncates_to_max_messages_keeping_most_recent(): void
    {
        // Use anonymous subclass to override maxHistoryMessages() without config()
        $service = new class extends ChatOrchestrationService {
            protected function maxHistoryMessages(): int { return 4; }
        };

        $messages = [];
        for ($i = 1; $i <= 10; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'assistant' : 'user', 'content' => "Pesan ke-{$i}"];
        }

        $history = $service->buildHistory($messages);

        // Only last 4 messages should be returned
        $this->assertCount(4, $history);
        $this->assertSame('Pesan ke-7', $history[0]['content']);
        $this->assertSame('Pesan ke-8', $history[1]['content']);
        $this->assertSame('Pesan ke-9', $history[2]['content']);
        $this->assertSame('Pesan ke-10', $history[3]['content']);
    }

    public function test_build_history_does_not_truncate_when_within_limit(): void
    {
        $service = new class extends ChatOrchestrationService {
            protected function maxHistoryMessages(): int { return 20; }
        };

        $messages = [
            ['role' => 'user', 'content' => 'Pesan 1'],
            ['role' => 'assistant', 'content' => 'Jawaban 1'],
            ['role' => 'user', 'content' => 'Pesan 2'],
        ];

        $history = $service->buildHistory($messages);

        $this->assertCount(3, $history);
    }

    public function test_single_document_source_uses_compact_reference_footer(): void
    {
        $service = new ChatOrchestrationService();

        $footer = $service->sanitizeAndFormatSources([
            ['filename' => 'memo-rapat.pdf'],
        ]);

        $this->assertSame("\n\n---\nDokumen rujukan: **memo-rapat.pdf**", $footer);
    }

    public function test_mixed_sources_use_adaptive_reference_block(): void
    {
        $service = new ChatOrchestrationService();

        $footer = $service->sanitizeAndFormatSources([
            ['type' => 'web', 'title' => 'Portal Resmi', 'url' => 'https://example.com/resmi'],
            ['filename' => 'briefing-harian.docx'],
        ]);

        $this->assertStringContainsString('**Rujukan:**', $footer);
        $this->assertStringContainsString('[Portal Resmi](https://example.com/resmi)', $footer);
        $this->assertStringContainsString('- Dokumen: briefing-harian.docx', $footer);
        $this->assertStringNotContainsString('🌐', $footer);
        $this->assertStringNotContainsString('`https://example.com/resmi`', $footer);
    }

    public function test_duplicate_sources_are_deduplicated_before_rendering(): void
    {
        $service = new ChatOrchestrationService();

        $footer = $service->sanitizeAndFormatSources([
            ['type' => 'web', 'title' => 'Portal Resmi', 'url' => 'https://example.com/resmi'],
            ['type' => 'web', 'title' => 'Portal Resmi', 'url' => 'https://example.com/resmi'],
            ['filename' => 'memo-rapat.pdf'],
            ['filename' => 'memo-rapat.pdf'],
        ]);

        $this->assertSame(1, substr_count($footer, 'https://example.com/resmi'));
        $this->assertSame(1, substr_count($footer, 'memo-rapat.pdf'));
    }

    public function test_extract_stream_metadata_buffers_split_sources_marker(): void
    {
        $service = new ChatOrchestrationService();

        $firstPass = $service->extractStreamMetadata('Jawaban awal [SOURCES:[{"url":"https://example.com"', '');

        $this->assertSame('Jawaban awal ', $firstPass[0]);
        $this->assertSame('[SOURCES:[{"url":"https://example.com"', $firstPass[1]);
        $this->assertNull($firstPass[3]);

        $secondPass = $service->extractStreamMetadata(',"title":"Contoh"}]]', $firstPass[1]);

        $this->assertSame('', $secondPass[0]);
        $this->assertSame('', $secondPass[1]);
        $this->assertSame([
            ['url' => 'https://example.com', 'title' => 'Contoh'],
        ], $secondPass[3]);
    }

    public function test_extract_stream_metadata_handles_source_strings_containing_brackets(): void
    {
        $service = new ChatOrchestrationService();
        $sourcesJson = json_encode([
            [
                'url' => 'https://example.com',
                'title' => 'Judul ] dengan bracket',
                'snippet' => 'Nilai "quoted"',
            ],
        ]);

        $result = $service->extractStreamMetadata(
            'Jawaban [SOURCES:'.$sourcesJson.'] selesai',
            ''
        );

        $this->assertSame('Jawaban  selesai', $result[0]);
        $this->assertSame('', $result[1]);
        $this->assertSame([
            [
                'url' => 'https://example.com',
                'title' => 'Judul ] dengan bracket',
                'snippet' => 'Nilai "quoted"',
            ],
        ], $result[3]);
    }

    public function test_extract_stream_metadata_removes_balanced_malformed_sources_without_throwing(): void
    {
        $service = new ChatOrchestrationService();

        $result = $service->extractStreamMetadata(
            'Jawaban [SOURCES:[{"url":}]] selesai',
            ''
        );

        $this->assertSame('Jawaban  selesai', $result[0]);
        $this->assertSame('', $result[1]);
        $this->assertNull($result[3]);
    }
}
