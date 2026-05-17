<?php

namespace Tests\Feature\Chat;

use App\Http\Controllers\Chat\ChatStreamController;
use App\Jobs\GenerateChatResponse;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\ChatOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Auth / ownership guards
    // -------------------------------------------------------------------------

    public function test_stream_returns_401_for_unauthenticated_user(): void
    {
        $owner = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Test conversation',
        ]);

        $this->get(route('chat.stream', ['conversationId' => $conversation->id]))
            ->assertRedirect(route('login'));
    }

    public function test_stream_returns_404_for_foreign_conversation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Owned by owner',
        ]);

        $this->actingAs($otherUser)
            ->get(route('chat.stream', ['conversationId' => $conversation->id]))
            ->assertNotFound();
    }

    public function test_stream_returns_404_for_nonexistent_conversation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('chat.stream', ['conversationId' => 999999]))
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // SSE output / header tests
    // -------------------------------------------------------------------------

    public function test_stream_returns_sse_content_type_header(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Header test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo',
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
                yield 'OK';
            }
        });

        $response = $this->actingAs($user)
            ->get(route('chat.stream', ['conversationId' => $conversation->id]));

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
    }

    public function test_stream_sends_sse_chunks_for_valid_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Streaming test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo',
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
                yield 'Halo ';
                yield 'dunia!';
            }
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertStringContainsString('event: chunk', $body);
        $this->assertStringContainsString('Halo ', $body);
        $this->assertStringContainsString('dunia!', $body);
        $this->assertStringContainsString('event: done', $body);
    }

    // -------------------------------------------------------------------------
    // History reconstructed from DB
    // -------------------------------------------------------------------------

    public function test_stream_uses_history_from_db_not_query_string(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'History from DB test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan dari DB',
        ]);

        $capturedMessages = null;

        $this->app->bind(AIService::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) extends AIService
            {
                public function __construct(private mixed &$captured)
                {
                    parent::__construct();
                }

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
                    $this->captured = $messages;
                    yield 'OK';
                }
            };
        });

        $this->runExecuteStream($user, $conversation);

        // History harus berisi pesan dari DB
        $this->assertNotNull($capturedMessages);
        $this->assertNotEmpty($capturedMessages);
        $lastMsg = end($capturedMessages);
        $this->assertSame('user', $lastMsg['role']);
        $this->assertSame('Pertanyaan dari DB', $lastMsg['content']);
    }

    // -------------------------------------------------------------------------
    // DB persistence
    // -------------------------------------------------------------------------

    public function test_stream_persists_assistant_message_to_db_after_stream(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Persistence test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
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
                yield 'Jawaban dari streaming.';
            }
        });

        $this->runExecuteStream($user, $conversation);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari streaming.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Idempotency — job selesai duluan, stream datang belakangan
    // -------------------------------------------------------------------------

    public function test_stream_does_not_duplicate_message_if_assistant_already_exists(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Idempotency test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
        ]);

        // Simulate job already saved the assistant message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari job.',
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
                yield 'Jawaban duplikat dari stream.';
            }
        });

        $this->runExecuteStream($user, $conversation);

        // Only one assistant message should exist
        $assistantCount = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count();

        $this->assertSame(1, $assistantCount, 'Tidak boleh ada duplikat assistant message');
    }

    // -------------------------------------------------------------------------
    // Race condition: stream selesai duluan, job berjalan belakangan
    // -------------------------------------------------------------------------

    public function test_race_stream_first_then_job_produces_single_assistant_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Race test: stream first',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan race',
        ]);

        // Step 1: Stream selesai duluan dan menyimpan assistant message
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
                yield 'Jawaban dari stream.';
            }
        });

        $this->runExecuteStream($user, $conversation);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari stream.',
        ]);

        // Step 2: Job berjalan belakangan — harus skip karena stream sudah simpan
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
                yield 'Jawaban dari job (seharusnya tidak tersimpan).';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pertanyaan race']],
        );
        $job->handle(app(AIService::class), new ChatOrchestrationService);

        // Hanya satu assistant message yang boleh ada
        $assistantCount = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count();

        $this->assertSame(1, $assistantCount, 'Race: stream selesai duluan, job tidak boleh duplikat');

        // Konten yang tersimpan harus dari stream, bukan job
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari stream.',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari job (seharusnya tidak tersimpan).',
        ]);
    }

    public function test_race_job_first_then_stream_produces_single_assistant_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Race test: job first',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan race job first',
        ]);

        // Step 1: Job selesai duluan
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
                yield 'Jawaban dari job.';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pertanyaan race job first']],
        );
        $job->handle(app(AIService::class), new ChatOrchestrationService);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari job.',
        ]);

        // Step 2: Stream selesai belakangan — harus skip karena job sudah simpan
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
                yield 'Jawaban dari stream (seharusnya tidak tersimpan).';
            }
        });

        $this->runExecuteStream($user, $conversation);

        // Hanya satu assistant message yang boleh ada
        $assistantCount = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count();

        $this->assertSame(1, $assistantCount, 'Race: job selesai duluan, stream tidak boleh duplikat');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari job.',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari stream (seharusnya tidak tersimpan).',
        ]);
    }

    // -------------------------------------------------------------------------
    // Error sentinel
    // -------------------------------------------------------------------------

    public function test_stream_saves_error_message_when_ai_returns_sentinel(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Error sentinel test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
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
                yield AIService::ERROR_SENTINEL.'❌ Kesalahan sistem.';
            }
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertStringContainsString('event: error', $body);
        $this->assertStringNotContainsString('event: chunk', $body);
        $this->assertStringNotContainsString(AIService::ERROR_SENTINEL, $body);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Document filtering
    // -------------------------------------------------------------------------

    public function test_stream_only_uses_owned_ready_documents(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Document filter test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo',
        ]);

        $readyDoc = Document::create([
            'user_id' => $user->id,
            'filename' => 'ready.pdf',
            'original_name' => 'ready.pdf',
            'file_path' => 'documents/'.$user->id.'/ready.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $foreignDoc = Document::create([
            'user_id' => $otherUser->id,
            'filename' => 'foreign.pdf',
            'original_name' => 'foreign.pdf',
            'file_path' => 'documents/'.$otherUser->id.'/foreign.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $captured = new \stdClass;
        $captured->documentIds = null;
        $captured->filenames = null;

        $this->app->bind(AIService::class, function () use ($captured) {
            return new class($captured) extends AIService
            {
                public function __construct(private \stdClass $captured)
                {
                    parent::__construct();
                }

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
                    $this->captured->documentIds = $document_ids;
                    $this->captured->filenames = $document_filenames;
                    yield 'OK';
                }
            };
        });

        $this->actingAs($user);

        $orchestrator = app(ChatOrchestrationService::class);
        $docContext = $orchestrator->getActiveDocumentContext([$readyDoc->id, $foreignDoc->id]);

        $controller = app(ChatStreamController::class);
        ob_start();
        $controller->executeStream(
            app(AIService::class),
            $orchestrator,
            [['role' => 'user', 'content' => 'Halo']],
            $docContext['filenames'],
            $docContext['ids'],
            $orchestrator->getSourcePolicy($docContext['filenames']),
            $orchestrator->shouldAllowAutoRealtimeWeb($docContext['filenames']),
            false,
            $conversation->id,
            $conversation,
            $user,
        );
        ob_get_clean();

        $this->assertNotNull($captured->documentIds);
        $this->assertContains($readyDoc->id, $captured->documentIds);
        $this->assertNotContains($foreignDoc->id, $captured->documentIds);
        $this->assertSame(['ready.pdf'], $captured->filenames);
    }

    // -------------------------------------------------------------------------
    // message-id event
    // -------------------------------------------------------------------------

    public function test_stream_sends_message_id_event_after_persistence(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Message ID event test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
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
                yield 'Jawaban tersimpan.';
            }
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertStringContainsString('event: final-content', $body);
        $this->assertStringContainsString('data: Jawaban tersimpan.', $body);
        $this->assertStringContainsString('event: message-id', $body);
        $this->assertStringContainsString('event: message-created-at', $body);
    }

    public function test_stream_final_content_event_includes_source_footer(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Final content source footer test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Ringkas dokumen.',
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
                yield 'Jawaban dengan sumber.';
                yield '[SOURCES:[{"filename":"dokumen-uji.pdf"}]]';
            }
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertStringContainsString('event: final-content', $body);
        $this->assertStringContainsString('data: Jawaban dengan sumber.', $body);
        $this->assertStringContainsString('data: ---', $body);
        $this->assertStringContainsString('data: Dokumen rujukan: **dokumen-uji.pdf**', $body);
        $this->assertStringContainsString('event: message-created-at', $body);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => "Jawaban dengan sumber.\n\n---\nDokumen rujukan: **dokumen-uji.pdf**",
        ]);
    }

    // -------------------------------------------------------------------------
    // Single-runner claim: stream skip AI jika job sudah selesai duluan
    // -------------------------------------------------------------------------

    public function test_stream_skips_ai_call_if_job_already_answered(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Single-runner claim test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
        ]);

        // Job sudah menyimpan assistant message sebelum stream dimulai
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari job.',
        ]);

        $aiCalled = false;
        $this->app->bind(AIService::class, function () use (&$aiCalled) {
            return new class($aiCalled) extends AIService
            {
                public function __construct(private bool &$called)
                {
                    parent::__construct();
                }

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
                    $this->called = true;
                    yield 'Chunk yang tidak boleh dikirim.';
                }
            };
        });

        $body = $this->runExecuteStream($user, $conversation);

        // AI tidak boleh dipanggil sama sekali
        $this->assertFalse($aiCalled, 'Stream tidak boleh memanggil AI jika job sudah menjawab');

        // Stream harus langsung kirim done tanpa chunk
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringNotContainsString('event: chunk', $body);

        // Hanya satu assistant message
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')->count());
    }

    // -------------------------------------------------------------------------
    // Error recovery: stream error tidak menimpa jawaban sukses dari job
    // -------------------------------------------------------------------------

    public function test_stream_error_does_not_overwrite_successful_job_answer(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Error recovery test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
        ]);

        // Job sudah menyimpan jawaban sukses
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban sukses dari job.',
            'is_error' => false,
        ]);

        // Stream error — saveErrorMessage harus skip karena job sudah simpan
        $orchestrator = app(ChatOrchestrationService::class);
        $this->actingAs($user);
        $result = $orchestrator->saveErrorMessage($conversation->id, 'Error dari stream.', $user->id);

        $this->assertNull($result, 'saveErrorMessage harus skip jika job sudah menyimpan jawaban');

        // Hanya satu assistant message (jawaban sukses dari job)
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')->count());

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban sukses dari job.',
            'is_error' => false,
        ]);
    }

    public function test_save_error_message_is_idempotent_when_called_twice(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Error idempotency test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);
        $this->actingAs($user);

        // Panggil saveErrorMessage dua kali (stream dan job keduanya error)
        $first = $orchestrator->saveErrorMessage($conversation->id, 'Error pertama.', $user->id);
        $second = $orchestrator->saveErrorMessage($conversation->id, 'Error kedua.', $user->id);

        $this->assertNotNull($first, 'Panggilan pertama harus berhasil menyimpan');
        $this->assertNull($second, 'Panggilan kedua harus skip (idempotensi)');

        // Hanya satu error message
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')->count());
    }

    public function test_stream_error_first_then_job_success_recovers_to_normal_answer(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Error then success recovery test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan pemulihan',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);

        // Step 1: stream menyimpan error duluan
        $this->actingAs($user);
        $savedError = $orchestrator->saveErrorMessage($conversation->id, 'Error awal dari stream.', $user->id);
        $this->assertNotNull($savedError);
        $this->assertTrue((bool) $savedError->is_error);

        // Step 2: job sukses belakangan harus memulihkan row yang sama menjadi normal
        $savedSuccess = $orchestrator->saveAssistantMessage($conversation->id, 'Jawaban sukses dari job.', $user->id);
        $this->assertNotNull($savedSuccess);
        $this->assertFalse((bool) $savedSuccess->is_error);
        $this->assertSame('Jawaban sukses dari job.', $savedSuccess->content);

        // Tetap satu assistant message setelah recovery
        $assistantMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get();

        $this->assertCount(1, $assistantMessages);
        $this->assertFalse((bool) $assistantMessages->first()->is_error);
        $this->assertSame('Jawaban sukses dari job.', $assistantMessages->first()->content);
    }

    public function test_job_skips_ai_when_stream_claim_is_active(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Mid-stream claim test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan mid-stream',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);
        $intentKey = $orchestrator->createStreamIntent($conversation->id);
        $this->assertNotNull($intentKey);

        $claimKey = $orchestrator->acquireStreamRunner($conversation->id);
        $this->assertNotNull($claimKey);

        $aiCalled = false;
        $this->app->bind(AIService::class, function () use (&$aiCalled) {
            return new class($aiCalled) extends AIService
            {
                public function __construct(private bool &$called)
                {
                    parent::__construct();
                }

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
                    $this->called = true;
                    yield 'Tidak boleh dipanggil saat stream claim aktif';
                }
            };
        });

        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pertanyaan mid-stream']],
        );
        $job->handle(app(AIService::class), $orchestrator);

        $this->assertFalse($aiCalled, 'Job harus skip AI call saat stream claim aktif');
        $this->assertSame(0, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count());

        $orchestrator->releaseStreamClaim($claimKey);
    }

    public function test_job_skips_ai_when_stream_already_persisted_answer(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Job skip after stream persisted',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan stream sudah jawab',
        ]);

        // Simulate stream already persisted assistant message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban dari stream.',
        ]);

        $aiCalled = false;
        $this->app->bind(AIService::class, function () use (&$aiCalled) {
            return new class($aiCalled) extends AIService
            {
                public function __construct(private bool &$called)
                {
                    parent::__construct();
                }

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
                    $this->called = true;
                    yield 'Tidak boleh dipanggil karena stream sudah jawab';
                }
            };
        });

        $this->actingAs($user);
        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pertanyaan stream sudah jawab']],
        );
        $job->handle(app(AIService::class), app(ChatOrchestrationService::class));

        $this->assertFalse($aiCalled, 'Job tidak boleh memanggil AI jika stream sudah menyimpan jawaban');
        $this->assertSame(1, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count(), 'Harus tetap satu assistant message');
    }

    public function test_execute_stream_acquires_claim_before_ai_call(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Claim lifecycle test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan claim lifecycle',
        ]);

        $claimWasActiveInsideSendChat = false;

        $this->app->bind(AIService::class, function () use ($conversation, &$claimWasActiveInsideSendChat) {
            return new class($conversation, $claimWasActiveInsideSendChat) extends AIService
            {
                public function __construct(
                    private Conversation $conversation,
                    private bool &$claimWasActiveInsideSendChat,
                ) {
                    parent::__construct();
                }

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
                    $orchestrator = app(ChatOrchestrationService::class);
                    $this->claimWasActiveInsideSendChat = $orchestrator->hasActiveStreamClaim((int) $this->conversation->id);
                    yield 'Chunk dengan claim aktif';
                }
            };
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertTrue($claimWasActiveInsideSendChat, 'Claim harus aktif saat sendChat() dipanggil');
        $this->assertStringContainsString('event: done', $body);

        $orchestrator = app(ChatOrchestrationService::class);
        $this->assertFalse($orchestrator->hasActiveStreamClaim($conversation->id), 'Claim harus dilepas setelah stream selesai');
    }

    public function test_execute_stream_adopts_pre_existing_claim_from_send_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Claim adoption test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan adopsi claim',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);

        $preClaimKey = $orchestrator->createStreamIntent($conversation->id);
        $this->assertNotNull($preClaimKey, 'Pre-claim harus berhasil dibuat');

        $aiCalled = false;
        $this->app->bind(AIService::class, function () use (&$aiCalled) {
            return new class($aiCalled) extends AIService
            {
                public function __construct(private bool &$called)
                {
                    parent::__construct();
                }

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
                    $this->called = true;
                    yield 'Stream jalan meski pre-claim ada';
                }
            };
        });

        $body = $this->runExecuteStream($user, $conversation);

        $this->assertTrue($aiCalled, 'Stream harus tetap memanggil AI meski pre-claim sudah ada');
        $this->assertStringContainsString('Stream jalan meski pre-claim ada', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertFalse($orchestrator->hasActiveStreamClaim($conversation->id), 'Claim harus dilepas setelah stream selesai');
    }

    // -------------------------------------------------------------------------
    // Duplicate stream adoption rejected
    // -------------------------------------------------------------------------

    public function test_duplicate_stream_adoption_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Duplicate stream test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan duplikat stream',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);

        // First: intent claim (sendMessage path)
        $intentKey = $orchestrator->createStreamIntent($conversation->id);
        $this->assertNotNull($intentKey, 'Intent claim harus berhasil');

        // Second: stream adopts intent → active
        $activeKey = $orchestrator->acquireStreamRunner($conversation->id);
        $this->assertNotNull($activeKey, 'Stream pertama harus bisa adopt intent');
        $this->assertSame($intentKey, $activeKey);

        // Third: duplicate stream tries to adopt active → rejected
        $duplicateKey = $orchestrator->acquireStreamRunner($conversation->id);
        $this->assertNull($duplicateKey, 'Stream duplikat harus ditolak saat claim sudah active');

        $orchestrator->releaseStreamClaim($activeKey);
    }

    public function test_direct_stream_claim_becomes_active_and_rejects_duplicate_runner(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Direct stream claim test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan direct stream',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);

        $activeKey = $orchestrator->acquireStreamRunner($conversation->id);
        $this->assertNotNull($activeKey, 'Stream pertama harus membuat active claim langsung');

        $duplicateKey = $orchestrator->acquireStreamRunner($conversation->id);
        $this->assertNull($duplicateKey, 'Stream kedua harus ditolak setelah direct stream active');

        $orchestrator->releaseStreamClaim($activeKey);
    }

    // -------------------------------------------------------------------------
    // No-stream fallback: intent claim stale, job runs AI
    // -------------------------------------------------------------------------

    public function test_job_runs_ai_after_intent_claim_expires(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'No-stream fallback test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan fallback no-stream',
        ]);

        $orchestrator = app(ChatOrchestrationService::class);

        // Simulate sendMessage() creating intent claim
        $claimKey = $orchestrator->createStreamIntent($conversation->id);
        $this->assertNotNull($claimKey);
        $this->assertTrue($orchestrator->hasActiveStreamClaim($conversation->id));

        // Simulate claim expiry (stream never connected)
        $orchestrator->releaseStreamClaim($claimKey);
        $this->assertFalse($orchestrator->hasActiveStreamClaim($conversation->id));

        // Job should now run AI since claim is gone
        $aiCalled = false;
        $this->app->bind(AIService::class, function () use (&$aiCalled) {
            return new class($aiCalled) extends AIService
            {
                public function __construct(private bool &$called)
                {
                    parent::__construct();
                }

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
                    $this->called = true;
                    yield 'Jawaban fallback dari job.';
                }
            };
        });

        $this->actingAs($user);
        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pertanyaan fallback no-stream']],
        );
        $job->handle(app(AIService::class), $orchestrator);

        $this->assertTrue($aiCalled, 'Job harus memanggil AI setelah claim stale/expired');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban fallback dari job.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Call executeStream() directly and capture its echo output.
     * History is reconstructed from DB messages (no query string).
     *
     * @param  array<int, int>  $documentIds
     */
    private function runExecuteStream(
        User $user,
        Conversation $conversation,
        array $documentIds = [],
        bool $webSearchMode = false,
    ): string {
        $this->actingAs($user);

        $orchestrator = app(ChatOrchestrationService::class);
        $docContext = $orchestrator->getActiveDocumentContext($documentIds);

        // Reconstruct history from DB (same as controller does)
        $dbMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id', 'asc')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => (string) $m->role, 'content' => (string) $m->content])
            ->all();
        $history = $orchestrator->buildHistory($dbMessages);

        $controller = app(ChatStreamController::class);

        ob_start();
        $controller->executeStream(
            app(AIService::class),
            $orchestrator,
            $history,
            $docContext['filenames'],
            $docContext['ids'],
            $orchestrator->getSourcePolicy($docContext['filenames']),
            $orchestrator->shouldAllowAutoRealtimeWeb($docContext['filenames']),
            $webSearchMode,
            $conversation->id,
            $conversation,
            $user,
        );

        return (string) ob_get_clean();
    }
}
