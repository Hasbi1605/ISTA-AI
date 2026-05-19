<?php

namespace Tests\Unit\Services\Admin;

use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AIUsageEventServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_started_records_pending_event_with_sanitized_metadata(): void
    {
        $user = User::factory()->create();
        $service = app(AIUsageEventService::class);

        $event = $service->started(
            feature: AIUsageEvent::FEATURE_CHAT,
            userId: (int) $user->id,
            metadata: [
                'conversation_id' => 42,
                'web_search_mode' => true,
                'document_count' => 0,
                'prompt' => 'Halo, ini prompt rahasia',
                'response_body' => 'Jawaban AI',
                'unknown_key' => 'should be dropped',
            ],
            requestId: 'req-123',
        );

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::FEATURE_CHAT, $event->feature);
        $this->assertSame(AIUsageEvent::ACTION_STARTED, $event->action);
        $this->assertSame(AIUsageEvent::STATUS_PENDING, $event->status);
        $this->assertSame('req-123', $event->request_id);
        $this->assertSame((int) $user->id, $event->user_id);
        $this->assertSame([
            'conversation_id' => 42,
            'web_search_mode' => true,
            'document_count' => 0,
        ], $event->metadata);
    }

    public function test_completed_stores_latency_and_subject(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test',
        ]);

        $service = app(AIUsageEventService::class);
        $event = $service->completed(
            feature: AIUsageEvent::FEATURE_DOCUMENT_RAG,
            userId: (int) $user->id,
            metadata: [
                'conversation_id' => $conversation->id,
                'response_length' => 1024,
            ],
            requestId: 'req-completed',
            latencyMs: 1234,
            subject: $conversation,
        );

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::ACTION_COMPLETED, $event->action);
        $this->assertSame(AIUsageEvent::STATUS_SUCCESS, $event->status);
        $this->assertSame(1234, $event->latency_ms);
        $this->assertSame((int) $conversation->id, $event->subject_id);
        $this->assertSame(Conversation::class, $event->subject_type);
    }

    public function test_failed_records_error_code_and_status_error(): void
    {
        $user = User::factory()->create();
        $service = app(AIUsageEventService::class);

        $event = $service->failed(
            feature: AIUsageEvent::FEATURE_CHAT,
            userId: (int) $user->id,
            metadata: [
                'conversation_id' => 7,
                'reason' => 'document_context_unavailable',
            ],
            requestId: 'req-failed',
            latencyMs: 50,
            errorCode: 'document_context_unavailable',
        );

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::ACTION_FAILED, $event->action);
        $this->assertSame(AIUsageEvent::STATUS_ERROR, $event->status);
        $this->assertSame('document_context_unavailable', $event->error_code);
    }

    public function test_metadata_drops_keys_that_look_like_raw_content(): void
    {
        $service = app(AIUsageEventService::class);
        $clean = $service->sanitizeMetadata([
            'conversation_id' => 1,
            'prompt' => 'rahasia',
            'message_content' => 'isi pesan',
            'document_text' => 'teks dokumen',
            'document_body' => 'body',
            'extracted_text' => 'extracted',
            'searchable_text' => 'cari ini',
            'preview' => 'preview',
            'transcript' => 'transcript',
            'context_text' => 'ctx',
            'reply' => 'reply',
            'answer' => 'answer',
            'response_body' => 'body',
            'raw_text' => 'raw',
        ]);

        $this->assertSame(['conversation_id' => 1], $clean);
    }

    public function test_metadata_truncates_long_strings(): void
    {
        $service = app(AIUsageEventService::class);
        $longValue = str_repeat('a', 1000);
        $clean = $service->sanitizeMetadata([
            'reason' => $longValue,
        ]);

        $this->assertArrayHasKey('reason', $clean);
        $this->assertSame(AIUsageEventService::MAX_METADATA_VALUE_LENGTH, mb_strlen($clean['reason']));
    }

    public function test_metadata_strips_unknown_keys_and_caps_arrays(): void
    {
        $service = app(AIUsageEventService::class);
        $clean = $service->sanitizeMetadata([
            'document_ids' => range(1, 200),
            'random_key' => 'should be dropped',
            'document_extension' => 'pdf',
        ]);

        $this->assertArrayNotHasKey('random_key', $clean);
        $this->assertArrayHasKey('document_ids', $clean);
        $this->assertArrayHasKey('document_extension', $clean);
        $this->assertLessThanOrEqual(AIUsageEventService::MAX_LIST_ITEMS, count($clean['document_ids']));
    }

    public function test_model_metadata_is_allowed_and_inferred_from_stream_label(): void
    {
        $service = app(AIUsageEventService::class);

        $metadata = $service->modelMetadata('GPT-4.1 Mini (Primary)');
        $clean = $service->sanitizeMetadata($metadata);

        $this->assertSame('GPT-4.1 Mini (Primary)', $clean['model_label']);
        $this->assertSame('openai/gpt-4.1-mini', $clean['model_name']);
        $this->assertSame('github_models', $clean['model_provider']);
    }

    public function test_knowledge_metadata_is_allowed_without_raw_content(): void
    {
        $service = app(AIUsageEventService::class);

        $clean = $service->sanitizeMetadata([
            'knowledge_used' => true,
            'knowledge_chunk_count' => 2,
            'knowledge_source_count' => 1,
            'knowledge_source_ids' => ['7'],
            'context_text' => 'raw knowledge chunk should never be stored',
        ]);

        $this->assertSame([
            'knowledge_used' => true,
            'knowledge_chunk_count' => 2,
            'knowledge_source_count' => 1,
            'knowledge_source_ids' => ['7'],
        ], $clean);
    }

    public function test_record_is_best_effort_when_database_throws(): void
    {
        Log::spy();
        $service = app(AIUsageEventService::class);

        // Force the create() call to fail by removing the underlying table.
        Schema::drop('ai_usage_events');

        $event = $service->started(
            feature: AIUsageEvent::FEATURE_CHAT,
            userId: null,
            metadata: ['conversation_id' => 1],
        );

        $this->assertNull($event);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_new_request_id_is_unique_string(): void
    {
        $service = app(AIUsageEventService::class);
        $a = $service->newRequestId();
        $b = $service->newRequestId();

        $this->assertNotSame($a, $b);
        $this->assertNotSame('', $a);
    }

    public function test_latency_helper_returns_non_negative_integer(): void
    {
        $service = app(AIUsageEventService::class);
        $latency = $service->latencyMsSince(microtime(true) - 0.05);
        $this->assertIsInt($latency);
        $this->assertGreaterThanOrEqual(0, $latency);
    }
}
