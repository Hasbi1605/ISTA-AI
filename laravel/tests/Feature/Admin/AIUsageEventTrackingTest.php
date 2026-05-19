<?php

namespace Tests\Feature\Admin;

use App\Jobs\GenerateChatResponse;
use App\Jobs\ProcessDocument;
use App\Jobs\RenderDocumentPreview;
use App\Http\Controllers\Chat\ChatStreamController;
use App\Livewire\Chat\ChatIndex;
use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Memo;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\ChatOrchestrationService;
use App\Services\DocumentLifecycleService;
use App\Services\Memo\MemoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AIUsageEventTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_send_message_returns_request_id_used_for_started_event(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Halo ISTA')
            ->call('sendMessage');

        Queue::assertPushed(GenerateChatResponse::class, function ($job) use ($user) {
            // The job receives the same request id that the started event uses.
            $event = AIUsageEvent::query()
                ->where('user_id', $user->id)
                ->where('action', AIUsageEvent::ACTION_STARTED)
                ->first();

            return $event !== null
                && $job->requestId !== null
                && $job->requestId === $event->request_id;
        });
    }

    public function test_stream_completed_event_uses_client_request_id(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Stream test',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo stream',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(
                array $messages,
                ?array $document_filenames = null,
                ?string $user_id = null,
                bool $force_web_search = false,
                ?string $source_policy = null,
                bool $allow_auto_realtime_web = true,
                ?array $document_ids = null,
                ?string $request_id = null,
            ): \Generator {
                yield "[MODEL:GPT-4.1 (Primary)]\n";
                yield 'OK';
            }
        });

        // Pre-record the started event so we can confirm both events share id.
        $sharedRequestId = 'req-shared-stream-1';
        app(\App\Services\Admin\AIUsageEventService::class)->started(
            feature: AIUsageEvent::FEATURE_CHAT,
            userId: (int) $user->id,
            metadata: [
                'conversation_id' => $conversation->id,
                'channel' => 'livewire',
            ],
            requestId: $sharedRequestId,
        );

        $this->actingAs($user);

        $orchestrator = app(ChatOrchestrationService::class);
        $controller = app(ChatStreamController::class);

        ob_start();
        $controller->executeStream(
            app(AIService::class),
            $orchestrator,
            [['role' => 'user', 'content' => 'Halo stream']],
            null,
            [],
            $orchestrator->getSourcePolicy(null),
            $orchestrator->shouldAllowAutoRealtimeWeb(null),
            false,
            $conversation->id,
            $conversation,
            $user,
            null,
            null,
            $sharedRequestId,
        );
        ob_get_clean();

        $events = AIUsageEvent::query()
            ->where('request_id', $sharedRequestId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(AIUsageEvent::ACTION_STARTED, $events[0]->action);
        $this->assertSame(AIUsageEvent::ACTION_COMPLETED, $events[1]->action);
        $this->assertSame('stream', $events[1]->metadata['channel'] ?? null);
        $this->assertSame('GPT-4.1 (Primary)', $events[1]->metadata['model_label'] ?? null);
        $this->assertSame('openai/gpt-4.1', $events[1]->metadata['model_name'] ?? null);
        $this->assertSame((int) $conversation->id, (int) ($events[1]->metadata['conversation_id'] ?? 0));
    }

    public function test_stream_failed_event_uses_client_request_id_on_error_sentinel(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Stream failure test',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo error',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(
                array $messages,
                ?array $document_filenames = null,
                ?string $user_id = null,
                bool $force_web_search = false,
                ?string $source_policy = null,
                bool $allow_auto_realtime_web = true,
                ?array $document_ids = null,
                ?string $request_id = null,
            ): \Generator {
                yield "[MODEL:GPT-4o (Primary)]\n";
                yield AIService::ERROR_SENTINEL.'AI tidak tersedia';
            }
        });

        $sharedRequestId = 'req-shared-stream-fail';
        app(\App\Services\Admin\AIUsageEventService::class)->started(
            feature: AIUsageEvent::FEATURE_CHAT,
            userId: (int) $user->id,
            metadata: [
                'conversation_id' => $conversation->id,
                'channel' => 'livewire',
            ],
            requestId: $sharedRequestId,
        );

        $this->actingAs($user);

        $orchestrator = app(ChatOrchestrationService::class);
        $controller = app(ChatStreamController::class);

        ob_start();
        $controller->executeStream(
            app(AIService::class),
            $orchestrator,
            [['role' => 'user', 'content' => 'Halo error']],
            null,
            [],
            $orchestrator->getSourcePolicy(null),
            $orchestrator->shouldAllowAutoRealtimeWeb(null),
            false,
            $conversation->id,
            $conversation,
            $user,
            null,
            null,
            $sharedRequestId,
        );
        ob_get_clean();

        $events = AIUsageEvent::query()
            ->where('request_id', $sharedRequestId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(AIUsageEvent::ACTION_FAILED, $events[1]->action);
        $this->assertSame('error_sentinel', $events[1]->error_code);
        $this->assertSame('stream', $events[1]->metadata['channel'] ?? null);
        $this->assertSame('GPT-4o (Primary)', $events[1]->metadata['model_label'] ?? null);
    }

    public function test_stream_controller_rejects_malformed_request_id_query_param(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'HTTP stream invalid id test',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo HTTP stream invalid',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(
                array $messages,
                ?array $document_filenames = null,
                ?string $user_id = null,
                bool $force_web_search = false,
                ?string $source_policy = null,
                bool $allow_auto_realtime_web = true,
                ?array $document_ids = null,
                ?string $request_id = null,
            ): \Generator {
                yield 'Done';
            }
        });

        $maliciousRequestId = "../etc/passwd ' OR 1=1";
        $reflection = new \ReflectionClass(ChatStreamController::class);
        $method = $reflection->getMethod('normalizeRequestId');
        $method->setAccessible(true);
        $controller = app(ChatStreamController::class);

        $this->assertNull($method->invoke($controller, $maliciousRequestId));
        $this->assertNull($method->invoke($controller, ''));
        $this->assertNull($method->invoke($controller, str_repeat('a', 65)));
        $this->assertSame('valid_id-123', $method->invoke($controller, ' valid_id-123 '));

        $this->actingAs($user);
        $orchestrator = app(ChatOrchestrationService::class);

        // Even when executeStream receives a null clientRequestId, it must
        // still record completed/failed events using a fresh request id.
        ob_start();
        $controller->executeStream(
            app(AIService::class),
            $orchestrator,
            [['role' => 'user', 'content' => 'Halo error']],
            null,
            [],
            $orchestrator->getSourcePolicy(null),
            $orchestrator->shouldAllowAutoRealtimeWeb(null),
            false,
            $conversation->id,
            $conversation,
            $user,
            null,
            null,
            null,
        );
        ob_get_clean();

        $completed = AIUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->latest('id')
            ->first();

        $this->assertNotNull($completed);
        $this->assertNotEmpty($completed->request_id);
    }

    public function test_chat_send_message_records_started_event_with_chat_feature(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Halo ISTA')
            ->call('sendMessage');

        Queue::assertPushed(GenerateChatResponse::class);

        $event = AIUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('feature', AIUsageEvent::FEATURE_CHAT)
            ->where('action', AIUsageEvent::ACTION_STARTED)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::STATUS_PENDING, $event->status);
        $this->assertNotNull($event->request_id);
        $this->assertIsArray($event->metadata);
        $this->assertSame(false, $event->metadata['web_search_mode'] ?? null);
        $this->assertSame(false, $event->metadata['has_documents'] ?? null);

        $stored = collect($event->metadata)->flatten(INF)->implode(' ');
        $this->assertStringNotContainsString('Halo ISTA', $stored);
    }

    public function test_chat_with_documents_records_document_rag_feature(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'doc.pdf',
            'original_name' => 'doc.pdf',
            'file_path' => 'documents/'.$user->id.'/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Ringkas dokumen')
            ->set('conversationDocuments', [$document->id])
            ->call('sendMessage');

        Queue::assertPushed(GenerateChatResponse::class);

        $event = AIUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('feature', AIUsageEvent::FEATURE_DOCUMENT_RAG)
            ->where('action', AIUsageEvent::ACTION_STARTED)
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->metadata['has_documents'] ?? false);
        $this->assertSame(1, $event->metadata['document_count'] ?? null);
    }

    public function test_chat_web_search_mode_records_web_search_feature(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Cari berita terkini')
            ->set('webSearchMode', true)
            ->call('sendMessage');

        $event = AIUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('feature', AIUsageEvent::FEATURE_WEB_SEARCH)
            ->where('action', AIUsageEvent::ACTION_STARTED)
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->metadata['web_search_mode'] ?? false);
    }

    public function test_chat_job_records_completion_event_on_success(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Halo',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                yield "[MODEL:GPT-4.1 Mini (Primary)]\n";
                yield 'Halo juga ';
                yield 'pengguna ';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: $conversation->id,
            userId: $user->id,
            history: [['role' => 'user', 'content' => 'Halo']],
            conversationDocuments: [],
            webSearchMode: false,
            requestId: 'req-job-1',
        );

        $job->handle(
            app(AIService::class),
            app(ChatOrchestrationService::class),
            app(\App\Services\Admin\AIUsageEventService::class),
        );

        $event = AIUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('feature', AIUsageEvent::FEATURE_CHAT)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->where('request_id', 'req-job-1')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::STATUS_SUCCESS, $event->status);
        $this->assertNotNull($event->metadata['response_length'] ?? null);
        $this->assertSame('GPT-4.1 Mini (Primary)', $event->metadata['model_label'] ?? null);
        $this->assertSame('openai/gpt-4.1-mini', $event->metadata['model_name'] ?? null);
        $this->assertNotNull($event->subject_id);
        $this->assertSame(Message::class, $event->subject_type);
    }

    public function test_chat_job_records_failure_event_on_error_sentinel(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Halo',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                yield "[MODEL:Llama 3.3 70B (Groq)]\n";
                yield AIService::ERROR_SENTINEL.'AI tidak tersedia';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: $conversation->id,
            userId: $user->id,
            history: [['role' => 'user', 'content' => 'Halo']],
            conversationDocuments: [],
            webSearchMode: false,
            requestId: 'req-job-fail',
        );

        $job->handle(
            app(AIService::class),
            app(ChatOrchestrationService::class),
            app(\App\Services\Admin\AIUsageEventService::class),
        );

        $event = AIUsageEvent::query()
            ->where('action', AIUsageEvent::ACTION_FAILED)
            ->where('request_id', 'req-job-fail')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::STATUS_ERROR, $event->status);
        $this->assertSame('error_sentinel', $event->error_code);
        $this->assertSame('Llama 3.3 70B (Groq)', $event->metadata['model_label'] ?? null);
        $this->assertSame('groq/llama-3.3-70b-versatile', $event->metadata['model_name'] ?? null);
    }

    public function test_document_upload_records_completed_event(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        app(DocumentLifecycleService::class)->uploadDocument(
            UploadedFile::fake()->create('referensi.pdf', 120, 'application/pdf'),
            (int) $user->id,
        );

        Queue::assertPushed(ProcessDocument::class);
        Queue::assertPushed(RenderDocumentPreview::class);

        $event = AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_DOCUMENT_UPLOAD)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AIUsageEvent::STATUS_SUCCESS, $event->status);
        $this->assertSame('pdf', $event->metadata['document_extension'] ?? null);
    }

    public function test_document_upload_records_failed_event_on_invalid_mime(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        try {
            app(DocumentLifecycleService::class)->uploadDocument(
                UploadedFile::fake()->create('readme.png', 10, 'image/png'),
                (int) $user->id,
            );
            $this->fail('Upload seharusnya menolak MIME tidak valid');
        } catch (\Throwable $e) {
            // expected
        }

        $event = AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_DOCUMENT_UPLOAD)
            ->where('action', AIUsageEvent::ACTION_FAILED)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('invalid_mime_type', $event->error_code);
    }

    public function test_memo_generation_records_completed_event(): void
    {
        Http::fake([
            '*/api/memos/generate-body' => Http::response($this->validMemoDocxBytes(), 200, [
                'X-Memo-Searchable-Text-B64' => base64_encode('Memo valid'),
                'X-Memo-Page-Size' => 'letter',
            ]),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $memo = app(MemoGenerationService::class)->generate(
            $user,
            'memo_internal',
            'Memo Test',
            'Buat memo test.',
            [],
            [
                'number' => 'EVAL-08/IST/YK/05/2026',
                'recipient' => 'Kepala Unit Layanan',
                'sender' => 'Kepala Istana Kepresidenan Yogyakarta',
                'subject' => 'Memo Test',
                'date' => '7 Mei 2026',
                'content' => 'Isi memo test.',
                'signatory' => 'Deni Mulyana',
                'page_size' => 'letter',
            ],
        );

        $event = AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_MEMO_GENERATION)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame((int) $memo->id, $event->subject_id);
        $this->assertSame(Memo::class, $event->subject_type);
        $this->assertSame('memo_internal', $event->metadata['memo_type'] ?? null);
        $this->assertSame(1, $event->metadata['memo_version'] ?? null);
    }

    public function test_memo_generation_records_failed_event_on_corrupt_docx(): void
    {
        Http::fake([
            '*/api/memos/generate-body' => Http::response("PK\x03\x04corrupt", 200, [
                'X-Memo-Searchable-Text-B64' => base64_encode('Memo korup'),
            ]),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        try {
            app(MemoGenerationService::class)->generate(
                $user,
                'memo_internal',
                'Memo Korup',
                'Buat memo korup.',
                [],
                [
                    'subject' => 'Memo Korup',
                    'page_size' => 'letter',
                ],
            );
            $this->fail('Memo generation seharusnya gagal pada DOCX korup.');
        } catch (\Throwable $e) {
            // expected
        }

        $event = AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_MEMO_GENERATION)
            ->where('action', AIUsageEvent::ACTION_FAILED)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('persist_failed', $event->error_code);
    }

    public function test_event_logging_failure_does_not_break_chat_send(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        // Drop the events table to force AIUsageEventService::record() to
        // hit the catch path. The service must swallow the failure so the
        // chat send flow keeps working end to end.
        \Illuminate\Support\Facades\Schema::drop('ai_usage_events');

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Halo')
            ->call('sendMessage');

        Queue::assertPushed(GenerateChatResponse::class);
        $component->assertHasNoErrors();
    }
}
