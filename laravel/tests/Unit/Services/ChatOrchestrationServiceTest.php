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

    public function test_build_history_preserves_additional_message_fields(): void
    {
        $service = new ChatOrchestrationService();

        $messages = [
            [
                'role' => 'user',
                'content' => 'Tolong siapkan ringkasan',
                'metadata' => ['trace_id' => 'abc-123'],
                'timestamp' => '2026-04-22T10:00:00+07:00',
            ],
        ];

        $history = $service->buildHistory($messages);

        $this->assertSame($messages[0]['metadata'], $history[0]['metadata']);
        $this->assertSame($messages[0]['timestamp'], $history[0]['timestamp']);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('Tolong siapkan ringkasan', $history[0]['content']);
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
}
