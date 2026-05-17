<?php

namespace Tests\Feature\Chat;

use App\Jobs\GenerateChatResponse;
use App\Jobs\ProcessDocument;
use App\Livewire\Chat\ChatIndex;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\ChatOrchestrationService;
use App\Services\DocumentExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class ChatUiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_send_message_rate_limited_blocks_before_ai_service_call(): void
    {
        $user = User::factory()->create();

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                throw new \RuntimeException('AIService should not be called when rate-limited.');
            }
        });

        $key = ChatIndex::class.':sendMessage:user-'.$user->id.':127.0.0.1';
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($key, 60);
        }

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Halo')
            ->call('sendMessage')
            ->assertHasErrors(['rate_limit']);
    }

    public function test_error_document_is_visible_but_not_selectable_and_can_be_reprocessed_by_owner(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'failed.pdf',
            'original_name' => 'failed.pdf',
            'file_path' => 'documents/'.$user->id.'/failed.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'error',
            'preview_status' => Document::PREVIEW_STATUS_FAILED,
            'preview_html_path' => 'previews/stale.html',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertSee('Gagal')
            ->assertSee('Tidak dipakai sebagai konteks AI sampai berhasil diproses ulang')
            ->set('selectedDocuments', [$document->id])
            ->call('addSelectedDocumentsToChat')
            ->assertSet('conversationDocuments', [])
            ->call('reprocessDocument', $document->id);

        $document->refresh();
        $this->assertSame('pending', $document->status);
        $this->assertSame(Document::PREVIEW_STATUS_PENDING, $document->preview_status);
        $this->assertNull($document->preview_html_path);
        Queue::assertPushed(ProcessDocument::class);
    }

    public function test_composer_and_document_toolbar_keep_persistent_copy_compact(): void
    {
        $user = User::factory()->create();

        Document::create([
            'user_id' => $user->id,
            'filename' => 'briefing.pdf',
            'original_name' => 'briefing.pdf',
            'file_path' => 'documents/'.$user->id.'/briefing.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertDontSee('Lampiran: PDF, DOCX, XLSX, atau CSV', false)
            ->assertSee('ISTA AI dapat keliru', false)
            ->assertSee('Pengaturan Akun', false)
            ->assertSee(route('profile', ['from' => 'chat']), false)
            ->assertDontSee('Memuat chat...', false)
            ->assertDontSee('Tambahkan ke chat', false)
            ->assertDontSee('Batal pilih semua', false)
            ->assertSee('Pakai', false)
            ->assertSee('Semua', false)
            ->assertSee('aria-label="Tambahkan dokumen terpilih ke chat"', false);
    }

    public function test_create_conversation_if_needed_throws_for_unowned_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $userA->id,
            'title' => 'Owned by A',
        ]);

        $service = new ChatOrchestrationService;

        $this->actingAs($userB);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Unauthorized conversation access.');

        $service->createConversationIfNeeded($conversation->id, 'Prompt dari user B');
    }

    public function test_get_document_filenames_scopes_owned_and_ready_documents_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedReady = Document::create([
            'user_id' => $user->id,
            'filename' => 'owned-ready.pdf',
            'original_name' => 'owned-ready.pdf',
            'file_path' => 'documents/'.$user->id.'/owned-ready.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $ownedProcessing = Document::create([
            'user_id' => $user->id,
            'filename' => 'owned-processing.pdf',
            'original_name' => 'owned-processing.pdf',
            'file_path' => 'documents/'.$user->id.'/owned-processing.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'processing',
        ]);

        $otherReady = Document::create([
            'user_id' => $otherUser->id,
            'filename' => 'other-ready.pdf',
            'original_name' => 'other-ready.pdf',
            'file_path' => 'documents/'.$otherUser->id.'/other-ready.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $service = new ChatOrchestrationService;

        $this->actingAs($user);

        $result = $service->getDocumentFilenames([
            $ownedReady->id,
            $ownedProcessing->id,
            $otherReady->id,
        ]);

        $this->assertSame(['owned-ready.pdf'], $result);
        $this->assertNull($service->getDocumentFilenames([$ownedProcessing->id, $otherReady->id]));
    }

    public function test_get_active_document_context_returns_ids_and_filenames_from_same_filter(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedReady = Document::create([
            'user_id' => $user->id,
            'filename' => 'ready-doc.pdf',
            'original_name' => 'ready-doc.pdf',
            'file_path' => 'documents/'.$user->id.'/ready-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $ownedProcessing = Document::create([
            'user_id' => $user->id,
            'filename' => 'processing-doc.pdf',
            'original_name' => 'processing-doc.pdf',
            'file_path' => 'documents/'.$user->id.'/processing-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'processing',
        ]);

        $foreignReady = Document::create([
            'user_id' => $otherUser->id,
            'filename' => 'foreign-ready.pdf',
            'original_name' => 'foreign-ready.pdf',
            'file_path' => 'documents/'.$otherUser->id.'/foreign-ready.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);

        $service = new ChatOrchestrationService;
        $this->actingAs($user);

        $context = $service->getActiveDocumentContext([
            $ownedReady->id,
            $ownedProcessing->id,
            $foreignReady->id,
        ]);

        // Only the owned+ready document should appear in both ids and filenames.
        $this->assertSame([$ownedReady->id], $context['ids']);
        $this->assertSame(['ready-doc.pdf'], $context['filenames']);
    }

    public function test_get_active_document_context_excludes_processing_and_foreign_documents_from_ids(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedProcessing = Document::create([
            'user_id' => $user->id,
            'filename' => 'stale.pdf',
            'original_name' => 'stale.pdf',
            'file_path' => 'documents/'.$user->id.'/stale.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'processing',
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

        $service = new ChatOrchestrationService;
        $this->actingAs($user);

        $context = $service->getActiveDocumentContext([$ownedProcessing->id, $foreignDoc->id]);

        $this->assertSame([], $context['ids']);
        $this->assertNull($context['filenames']);
    }

    public function test_generate_chat_response_job_only_sends_ready_owned_document_ids_to_ai(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test context filter',
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

        $processingDoc = Document::create([
            'user_id' => $user->id,
            'filename' => 'processing.pdf',
            'original_name' => 'processing.pdf',
            'file_path' => 'documents/'.$user->id.'/processing.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'processing',
        ]);

        $capturedDocumentIds = null;
        $capturedFilenames = null;

        // Use an object container so the reference persists through the closure
        // and anonymous-class boundary. Arrow functions capture by value, so we
        // need a mutable wrapper to propagate back to the assertion scope.
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
                    yield '';
                }
            };
        });

        $job = new GenerateChatResponse(
            $conversation->id,
            $user->id,
            [['role' => 'user', 'content' => 'Halo']],
            [$readyDoc->id, $processingDoc->id],
        );

        app()->call([$job, 'handle']);

        // Processing document must never reach the AI layer.
        $this->assertNotNull($captured->documentIds);
        $this->assertContains($readyDoc->id, $captured->documentIds);
        $this->assertNotContains($processingDoc->id, $captured->documentIds);
        $this->assertSame(['ready.pdf'], $captured->filenames);
    }

    public function test_send_message_rejects_prompt_over_8000_chars_before_rate_limit_or_ai_call(): void
    {
        $user = User::factory()->create();

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                throw new \RuntimeException('AIService should not be called for invalid prompt.');
            }
        });

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', str_repeat('a', 8001))
            ->call('sendMessage')
            ->assertHasErrors(['prompt' => 'max']);
    }

    public function test_send_message_does_not_persist_message_for_unauthorized_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversationA = Conversation::create([
            'user_id' => $userA->id,
            'title' => 'Owned by user A',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                throw new \RuntimeException('AIService should not be called for unauthorized conversation access.');
            }
        });

        Livewire::actingAs($userB)
            ->test(ChatIndex::class)
            ->set('currentConversationId', $conversationA->id)
            ->set('prompt', 'Pesan tidak berizin')
            ->call('sendMessage');

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversationA->id,
            'content' => 'Pesan tidak berizin',
        ]);
        $this->assertCount(0, Message::where('conversation_id', $conversationA->id)->get());
    }

    public function test_save_answer_to_google_drive_rate_limited_returns_error_before_export_service_call(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Drive export limiter',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban untuk diuji.',
        ]);

        $this->app->bind(DocumentExportService::class, fn () => new class extends DocumentExportService
        {
            public function exportContent(string $html, string $format, string $basename = 'document'): array
            {
                throw new \RuntimeException('DocumentExportService should not be called when rate-limited.');
            }
        });

        $key = ChatIndex::class.':saveAnswerToGoogleDrive:user-'.$user->id.':127.0.0.1';
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($key, 60);
        }

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('saveAnswerToGoogleDrive', $message->id, 'pdf')
            ->assertReturned([
                'ok' => false,
                'message' => 'Terlalu banyak permintaan ekspor Google Drive. Coba lagi sebentar.',
            ]);
    }

    public function test_chat_page_renders_multiline_and_document_feedback_hooks(): void
    {
        $user = User::factory()->create();
        Conversation::create([
            'user_id' => $user->id,
            'title' => 'History test',
        ]);
        $olderConversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Older history hook test',
        ]);
        Conversation::withoutTimestamps(fn () => $olderConversation->forceFill([
            'created_at' => now('Asia/Jakarta')->subDays(2),
            'updated_at' => now('Asia/Jakarta')->subDays(2),
        ])->save());
        $pendingConversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Pending history test',
        ]);
        Message::create([
            'conversation_id' => $pendingConversation->id,
            'role' => 'user',
            'content' => 'Prompt yang masih menunggu jawaban',
        ]);

        $response = $this->actingAs($user)->get('/chat');
        $chatPageJs = file_get_contents(resource_path('js/chat-page.js'));
        $this->assertIsString($chatPageJs);
        $appCss = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($appCss);
        $this->assertStringContainsString('pendingConversationIds', $chatPageJs);
        $this->assertStringContainsString('completedConversationIds', $chatPageJs);
        $this->assertStringContainsString('normalizeWirePayload', $chatPageJs);
        $this->assertStringContainsString('this.activeConversationId === conversationId', $chatPageJs);
        $this->assertStringContainsString('this.markConversationRead(this.activeConversationId)', $chatPageJs);
        $this->assertStringContainsString('window.history.pushState', $chatPageJs);
        $this->assertStringContainsString('openHistorySections', $chatPageJs);
        $this->assertStringContainsString('historySearch', $chatPageJs);
        $this->assertStringContainsString('toggleAllHistory', $chatPageJs);
        $this->assertStringContainsString('CHAT_HISTORY_SECTIONS_STORAGE_KEY', $chatPageJs);
        $this->assertStringContainsString('allHistorySectionsOpen', $chatPageJs);
        $this->assertStringContainsString('navigationToken', $chatPageJs);
        $this->assertStringContainsString('queueNavigationUntilMessageAck', $chatPageJs);
        $this->assertStringContainsString('CHAT_MESSAGE_ACK_TIMEOUT_MS', $chatPageJs);
        $this->assertStringContainsString('clearMessageAckWait', $chatPageJs);
        $this->assertStringContainsString('user-message-rejected', $chatPageJs);
        $this->assertStringContainsString('Tunggu jawaban sebelumnya selesai sebelum mengirim pesan baru.', $chatPageJs);
        $this->assertStringContainsString('syncPendingConversations', $chatPageJs);
        $this->assertStringContainsString('chat-pending-state-updated', $chatPageJs);
        $this->assertStringContainsString('activeEventSources: {}', $chatPageJs);
        $this->assertStringContainsString('isActiveConversation(conversationId)', $chatPageJs);
        $this->assertStringContainsString('ensureVisibleStreamPlaceholder(conversationId, loadingContext)', $chatPageJs);
        $this->assertStringContainsString('this.closeChatStream(conversationId)', $chatPageJs);
        $this->assertStringContainsString('this.isActiveConversation(targetConversationId)', $chatPageJs);
        $this->assertStringContainsString('createAssistantTypewriterState', $chatPageJs);
        $this->assertStringContainsString("Alpine.data('assistantTypewriter'", $chatPageJs);
        $this->assertStringContainsString('queueAssistantTypewriterFinalText(finalText)', $chatPageJs);
        $this->assertStringContainsString('queueFinalStreamingText(finalText)', $chatPageJs);
        $this->assertStringContainsString('refreshPendingAfterStreamingSettles(conversationId, streamedMessageId', $chatPageJs);
        $this->assertStringContainsString('refreshPendingChatState(streamedMessageId, this.isActiveConversation(conversationId))', $chatPageJs);
        $this->assertStringContainsString('shouldPreserveCurrentStream', $chatPageJs);
        $this->assertStringContainsString('preserveStream', $chatPageJs);
        $this->assertStringContainsString('afterStreamingTypewriterSettles(() => {', $chatPageJs);
        $this->assertStringContainsString('revealStreamingSources()', $chatPageJs);
        $this->assertStringContainsString('resolvedMessageId()', $chatPageJs);
        $this->assertStringContainsString('resolvedHtml()', $chatPageJs);
        $this->assertStringContainsString('CHAT_SOURCE_REVEAL_BEFORE_REFRESH_MS', $chatPageJs);
        $this->assertStringContainsString('CHAT_LOADING_PHASE_DELAY_MS', $chatPageJs);
        $this->assertStringContainsString('CHAT_PENDING_STALE_WARNING_MS', $chatPageJs);
        $this->assertStringNotContainsString('this.streamingText = this.streamingFinalText', $chatPageJs);
        $this->assertStringNotContainsString('activeEventSource: null', $chatPageJs);
        $this->assertStringNotContainsString('this.activeEventSource = es', $chatPageJs);
        $this->assertStringNotContainsString('showAllHistory', $chatPageJs);
        $this->assertStringContainsString('sectionHasActivity', $chatPageJs);
        $this->assertStringNotContainsString('.chat-form:focus-within', $appCss);
        $this->assertStringContainsString('.chat-input:focus', $appCss);

        $response
            ->assertOk()
            ->assertSee('x-on:keydown.enter="handleEnterKey($event)"', false)
            ->assertSee('x-show="!optimisticUserMessage && !isSwitchingConversation"', false)
            ->assertSee('Menghapus dokumen...', false)
            ->assertSee('conversation-documents-preview', false)
            ->assertDontSee('Membuka chat...', false)
            ->assertSee('x-on:conversation-loading.window="isSwitchingConversation = true"', false)
            ->assertSee('x-on:conversation-activated.window="setActiveConversation($event.detail.id)"', false)
            ->assertDontSee(':disabled="isNavigating"', false)
            ->assertSee('data-chat-history-id=', false)
            ->assertSee('pendingConversationIds:', false)
            ->assertSee('isPending(', false)
            ->assertSee('isCompleteUnread(', false)
            ->assertSee('Jawaban baru tersedia', false)
            ->assertSee('h-3 w-3 shrink-0 rounded-full border', false)
            ->assertSee('min-w-0 flex-1 truncate', false)
            ->assertSee('bg-sky-500', false)
            ->assertSee('wire:poll.3s="refreshPendingChatState"', false)
            ->assertSee('navigateToConversation($event,', false)
            ->assertSee('navigateToNewChat($event)', false)
            ->assertSee('chat-history-item', false)
            ->assertSee('wire:key="chat-history-visible-', false)
            ->assertSee('Cari riwayat...', false)
            ->assertSee('Lihat semua', false)
            ->assertSee('focus:outline-none', false)
            ->assertSee('focus:ring-ista-primary/15', false)
            ->assertDontSee('Bersihkan pencarian riwayat', false)
            ->assertSee('historySectionKeys:', false)
            ->assertSee('x-text="allHistorySectionsOpen() ? \'Ringkas\' : \'Lihat semua\'"', false)
            ->assertSee('data-chat-history-section=', false)
            ->assertSee('x-show="isHistorySectionOpen(', false)
            ->assertSee('sectionHasActivity(', false)
            ->assertSee('openGoogleDrivePicker()', false)
            ->assertSee('open-google-drive-picker', false)
            ->assertSee('Ambil dokumen dari Google Drive Kantor', false)
            ->assertSee('images/icons/google-drive.svg', false)
            ->assertSee('Pilih file untuk chat', false)
            ->assertSee('chat-tab-switch', false)
            ->assertSee('activeTab === \'chat\'', false)
            ->assertDontSee('wire:click="$set(\'tab\', \'chat\')"', false)
            ->assertSee('data-chat-conversation-id=', false)
            ->assertSee('data-chat-last-message-role=', false)
            ->assertSee('data-chat-last-user-message-created-at=', false)
            ->assertSee('x-if="streamedAssistantMessageId && streamingText !== \'\'"', false)
            ->assertSee('messageId: () => streamedAssistantMessageId', false)
            ->assertSee('html: () => streamingHtml', false);
    }

    public function test_chat_route_with_conversation_id_loads_selected_conversation_messages(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation terpilih via URL',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo dari history URL',
        ]);

        $response = $this->actingAs($user)->get(route('chat', ['id' => $conversation->id]));

        $response
            ->assertOk()
            ->assertSee('Halo dari history URL', false)
            ->assertSee('data-chat-last-user-message-created-at=', false)
            ->assertSee('data-chat-history-id="'.$conversation->id.'"', false);
    }

    public function test_chat_route_with_foreign_conversation_id_returns_404(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Conversation user lain',
        ]);

        $this->actingAs($otherUser)
            ->get(route('chat', ['id' => $conversation->id]))
            ->assertNotFound();
    }

    public function test_send_message_queues_response_and_allows_loading_another_conversation_without_waiting(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $conversationB = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation B',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class);

        $component
            ->set('prompt', 'Pesan pengguna awal')
            ->call('sendMessage', aiService: app(AIService::class));

        $conversationA = Conversation::query()
            ->where('user_id', $user->id)
            ->where('title', 'like', 'Pesan pengguna awal%')
            ->firstOrFail();

        $component
            ->assertSet('currentConversationId', $conversationA->id)
            ->assertSet('pendingConversationIds', [$conversationA->id])
            ->assertDispatched('user-message-acked')
            ->assertDispatched('conversation-activated', id: $conversationA->id)
            ->assertDispatched('chat-pending-state-updated', pendingConversationIds: [$conversationA->id]);

        Queue::assertPushed(GenerateChatResponse::class, function (GenerateChatResponse $job) use ($conversationA, $user) {
            return $job->conversationId === (int) $conversationA->id
                && $job->userId === (int) $user->id
                && $job->history[count($job->history) - 1]['content'] === 'Pesan pengguna awal';
        });

        $component
            ->call('loadConversation', $conversationB->id)
            ->assertSet('currentConversationId', $conversationB->id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationA->id,
            'role' => 'user',
            'content' => 'Pesan pengguna awal',
        ]);
    }

    public function test_send_message_rejects_pending_conversation_with_explicit_frontend_signal(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation masih pending',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan sebelumnya masih diproses',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $conversation->id])
            ->set('prompt', 'Pertanyaan kedua terlalu cepat')
            ->call('sendMessage', aiService: app(AIService::class))
            ->assertReturned([
                'conversationId' => $conversation->id,
                'messageId' => null,
                'rejected' => true,
                'reason' => 'pending_response',
            ])
            ->assertDispatched('user-message-rejected', conversationId: $conversation->id, reason: 'pending_response');

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan kedua terlalu cepat',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_generate_chat_response_skips_assistant_persistence_when_origin_conversation_deleted_before_job_runs(): void
    {
        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation asal',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                throw new \RuntimeException('AIService should not be called for deleted conversation.');
            }
        });

        $conversation->delete();
        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pesan user sebelum hapus conversation']],
        );
        $job->handle(app(AIService::class), new ChatOrchestrationService);

        $this->assertDatabaseMissing('conversations', [
            'id' => $conversation->id,
        ]);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Maaf, jawaban gagal diproses. Silakan coba kirim ulang.',
        ]);
    }

    public function test_generate_chat_response_saves_error_message_when_ai_service_returns_sentinel(): void
    {
        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation untuk error sentinel test',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                yield AIService::ERROR_SENTINEL.'❌ Kesalahan sistem saat menghubungi otak AI. Silakan coba lagi nanti.';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pesan user']],
        );

        $job->handle(app(AIService::class), new ChatOrchestrationService);

        // Harus tersimpan sebagai is_error=true, bukan jawaban normal
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '❌ Kesalahan sistem saat menghubungi otak AI. Silakan coba lagi nanti.',
            'is_error' => true,
        ]);

        // Tidak boleh ada pesan normal (is_error=false) dengan konten error
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => false,
            'content' => '❌ Kesalahan sistem saat menghubungi otak AI. Silakan coba lagi nanti.',
        ]);
    }

    public function test_message_is_error_is_cast_to_boolean(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Cast test',
        ]);

        $errorMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Error message',
            'is_error' => true,
        ]);

        $normalMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Normal message',
            'is_error' => false,
        ]);

        // Harus selalu boolean, bukan int atau string
        $this->assertIsBool($errorMessage->fresh()->is_error);
        $this->assertIsBool($normalMessage->fresh()->is_error);
        $this->assertTrue($errorMessage->fresh()->is_error);
        $this->assertFalse($normalMessage->fresh()->is_error);
    }

    public function test_error_assistant_message_shows_failure_label_in_chat_ui(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Error UI label test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Apa isi dokumen ini?',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini.',
            'is_error' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $conversation->id])
            ->assertSee('Gagal memproses jawaban')
            ->assertSee('Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini.');
    }

    public function test_generate_chat_response_persists_assistant_message_to_origin_conversation(): void
    {
        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Conversation job asal',
        ]);

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                yield 'Jawaban AI dari background job.';
            }
        });

        $job = new GenerateChatResponse(
            conversationId: (int) $conversation->id,
            userId: (int) $user->id,
            history: [['role' => 'user', 'content' => 'Pesan user untuk job']],
        );

        $job->handle(app(AIService::class), new ChatOrchestrationService);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban AI dari background job.',
        ]);
    }

    public function test_send_message_dispatches_ack_and_queues_response_with_conversation_id_payload(): void
    {
        Queue::fake();
        Cache::flush();

        $user = User::factory()->create();

        $this->app->bind(AIService::class, fn () => new class extends AIService
        {
            public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null, bool $force_web_search = false, ?string $source_policy = null, bool $allow_auto_realtime_web = true, ?array $document_ids = null, ?string $request_id = null): \Generator
            {
                yield 'Jawaban AI untuk payload event.';
            }
        });

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('prompt', 'Halo payload event')
            ->call('sendMessage', aiService: app(AIService::class));

        $conversationId = (int) $component->get('currentConversationId');

        $component->assertDispatched('user-message-acked', function (string $_event, array $payload) use ($conversationId) {
            return (int) ($payload['conversationId'] ?? 0) === $conversationId
                && (int) ($payload['messageId'] ?? 0) > 0;
        });

        Queue::assertPushed(GenerateChatResponse::class, function (GenerateChatResponse $job) use ($conversationId, $user) {
            return $job->conversationId === $conversationId
                && $job->userId === (int) $user->id;
        });

        $claimKey = app(ChatOrchestrationService::class)->streamClaimKeyForLatestUserMessage($conversationId);
        $this->assertNotNull($claimKey);
        $this->assertTrue(Cache::has($claimKey), 'Claim stream harus dibuat saat sendMessage agar fallback job deterministik.');
    }

    public function test_save_assistant_message_returns_null_when_create_hits_conversation_fk_race(): void
    {
        $service = new class extends ChatOrchestrationService
        {
            protected function conversationExists(int $conversationId, int $userId): bool
            {
                return true;
            }

            protected function createAssistantMessage(int $conversationId, string $content): Message
            {
                $pdoException = new \PDOException('Cannot add or update a child row: a foreign key constraint fails');
                $pdoException->errorInfo = ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'];

                throw new QueryException(
                    'mysql',
                    'insert into `messages` (`conversation_id`, `role`, `content`) values (?, ?, ?)',
                    [$conversationId, 'assistant', $content],
                    $pdoException
                );
            }
        };

        $result = $service->saveAssistantMessage(999999, 'Assistant response', 1);

        $this->assertNull($result);
    }

    public function test_save_assistant_message_returns_null_for_conversation_not_owned_by_caller(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Owned by owner',
        ]);

        $service = new ChatOrchestrationService;

        // Pass otherUser's ID explicitly — should be blocked because conversation belongs to owner
        $result = $service->saveAssistantMessage($conversation->id, 'Assistant response should be blocked', $otherUser->id);

        $this->assertNull($result);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Assistant response should be blocked',
        ]);
    }

    public function test_save_assistant_message_uses_explicit_user_id_not_auth_facade(): void
    {
        // Verifikasi bahwa saveAssistantMessage menggunakan userId eksplisit,
        // bukan Auth::id(). Ini memastikan job queue tetap bisa menyimpan
        // jawaban meski Auth facade tidak ter-set dengan benar.
        $owner = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Owner conversation',
        ]);

        $service = new ChatOrchestrationService;

        // Tidak ada actingAs — Auth::id() adalah null
        // Tapi pass userId eksplisit milik owner → harus berhasil simpan
        $result = $service->saveAssistantMessage($conversation->id, 'Jawaban AI tersimpan', $owner->id);

        $this->assertNotNull($result);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban AI tersimpan',
        ]);
    }

    public function test_build_history_normalizes_quoted_max_history_messages_config(): void
    {
        // Simulate runtime config with quoted value (e.g. from .env parsing quirk).
        // Without normalization, (int)' "4" ' = 0, max(1,0) = 1 — chat would be
        // truncated to 1 message, losing almost all context.
        config()->set('services.ai_service.max_history_messages', ' "4" ');

        $service = new ChatOrchestrationService;

        $messages = [];
        for ($i = 1; $i <= 10; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'assistant' : 'user', 'content' => "Pesan ke-{$i}"];
        }

        $history = $service->buildHistory($messages);

        // Should truncate to 4 (or fewer after leading-assistant drop), not 1
        $this->assertGreaterThan(1, count($history),
            'Quoted config value should normalize to 4, not 1');
        $this->assertLessThanOrEqual(4, count($history));
        $this->assertSame('user', $history[0]['role'],
            'History must start with a user message');
    }

    public function test_loading_conversation_dispatches_active_history_event(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Selected history',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('loadConversation', $conversation->id)
            ->assertSet('currentConversationId', $conversation->id)
            ->assertDispatched('conversation-activated', id: $conversation->id);
    }

    public function test_chat_answers_render_copy_share_and_export_actions(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Answer toolbar test',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => "Ini jawaban contoh.\n\n- Poin pertama\n- Poin kedua",
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('messages', [$message->toArray()])
            ->assertSee('data-answer-actions', false)
            ->assertSee('Salin', false)
            ->assertSee('role="status"', false)
            ->assertSee('Tersalin', false)
            ->assertSee('Bagikan', false)
            ->assertDontSee('text-[#25D366]', false)
            ->assertSee('Upload ke Google Drive', false)
            ->assertSee('images/icons/google-drive.svg', false)
            ->assertSee('driveButtonLabel()', false)
            ->assertSee('Upload ke Drive', false)
            ->assertSee('Ekspor', false)
            ->assertSee('PDF', false)
            ->assertSee('DOCX', false)
            ->assertSee('XLSX', false)
            ->assertSee('CSV', false);
    }

    public function test_multiple_chat_answers_render_distinct_action_scopes(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Multiple answer toolbar test',
        ]);

        $firstMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban pertama untuk dibagikan.',
        ]);

        $secondMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban kedua harus punya tombol sendiri.',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('messages', [$firstMessage->toArray(), $secondMessage->toArray()])
            ->assertSee('wire:key="chat-message-'.$firstMessage->id.'"', false)
            ->assertSee('wire:key="chat-message-'.$secondMessage->id.'"', false)
            ->assertSee('wire:key="chat-answer-actions-'.$firstMessage->id.'"', false)
            ->assertSee('wire:key="chat-answer-actions-'.$secondMessage->id.'"', false)
            ->assertSee('data-answer-message-id="'.$firstMessage->id.'"', false)
            ->assertSee('data-answer-message-id="'.$secondMessage->id.'"', false);
    }

    public function test_google_drive_documents_do_not_show_special_source_badge_in_sidebar(): void
    {
        $user = User::factory()->create();

        Document::create([
            'user_id' => $user->id,
            'filename' => 'surat-drive.pdf',
            'original_name' => 'surat-drive.pdf',
            'file_path' => 'documents/'.$user->id.'/surat-drive.pdf',
            'source_provider' => 'google_drive',
            'source_external_id' => 'drive-file-id',
            'source_synced_at' => now(),
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1234,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertSee('surat-drive.pdf', false);

        $this->assertStringNotContainsString('>Google Drive</span>', $component->html());
    }

    public function test_latest_chat_answer_still_renders_actions(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Latest answer toolbar test',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban terbaru juga punya toolbar.',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('messages', [$message->toArray()])
            ->set('newMessageId', $message->id)
            ->assertSee('data-answer-actions', false)
            ->assertSee('Salin', false)
            ->assertSee('Bagikan', false)
            ->assertSee('Ekspor', false);
    }

    public function test_loading_conversation_clears_latest_message_marker(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Clear latest marker test',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Pesan dari history.',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('newMessageId', $message->id)
            ->call('loadConversation', $conversation->id)
            ->assertSet('newMessageId', null);
    }

    public function test_loading_conversation_can_preserve_latest_message_marker_for_typewriter(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Preserve latest marker test',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Pesan baru dari AI.',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('newMessageId', $message->id)
            ->call('loadConversation', $conversation->id, false)
            ->assertSet('newMessageId', $message->id)
            ->assertSee('wire:key="msg-typing-'.$message->id.'"', false);
    }

    public function test_refresh_pending_chat_state_loads_completed_active_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Pending refresh test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Tolong jawab setelah job selesai.',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $conversation->id])
            ->assertSet('pendingConversationIds', [$conversation->id]);

        $assistant = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban background sudah selesai.',
        ]);
        $conversation->touch();

        $component
            ->call('refreshPendingChatState')
            ->assertSet('pendingConversationIds', [])
            ->assertSet('newMessageId', $assistant->id)
            ->assertSee('Jawaban background sudah selesai.', false)
            ->assertDispatched('chat-pending-state-updated', pendingConversationIds: [])
            ->assertDispatched('assistant-message-persisted', function (string $_event, array $payload) use ($conversation, $assistant) {
                return (int) ($payload['conversationId'] ?? 0) === (int) $conversation->id
                    && (int) ($payload['messageId'] ?? 0) === (int) $assistant->id;
            });
    }

    public function test_refresh_pending_chat_state_does_not_replay_typewriter_for_streamed_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'SSE streamed refresh test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Tolong jawab lewat SSE.',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $conversation->id])
            ->assertSet('pendingConversationIds', [$conversation->id]);

        $assistant = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban SSE sudah tampil live.',
        ]);
        $conversation->touch();

        $component
            ->call('refreshPendingChatState', $assistant->id)
            ->assertSet('pendingConversationIds', [])
            ->assertSet('newMessageId', null)
            ->assertSee('Jawaban SSE sudah tampil live.', false)
            ->assertDontSee('wire:key="msg-typing-'.$assistant->id.'"', false)
            ->assertDispatched('chat-pending-state-updated', pendingConversationIds: [])
            ->assertDispatched('assistant-message-persisted', function (string $_event, array $payload) use ($conversation, $assistant) {
                return (int) ($payload['conversationId'] ?? 0) === (int) $conversation->id
                    && (int) ($payload['messageId'] ?? 0) === (int) $assistant->id;
            });
    }

    public function test_refresh_pending_chat_state_can_preserve_streamed_message_bubble(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Preserved SSE streamed refresh test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Tolong jawab lewat SSE dan jangan flicker.',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $conversation->id])
            ->assertSet('pendingConversationIds', [$conversation->id]);

        $assistant = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban SSE tetap memakai bubble live.',
        ]);
        $conversation->touch();

        $component
            ->call('refreshPendingChatState', $assistant->id, true)
            ->assertSet('pendingConversationIds', [])
            ->assertSet('newMessageId', null)
            ->assertSet('preservedStreamMessageId', $assistant->id)
            ->assertSee('data-chat-last-assistant-message-id="'.$assistant->id.'"', false)
            ->assertDontSee('wire:key="chat-message-'.$assistant->id.'"', false)
            ->assertDontSee('data-answer-message-id="'.$assistant->id.'"', false)
            ->assertDispatched('chat-pending-state-updated', pendingConversationIds: [])
            ->assertDispatched('assistant-message-persisted', function (string $_event, array $payload) use ($conversation, $assistant) {
                return (int) ($payload['conversationId'] ?? 0) === (int) $conversation->id
                    && (int) ($payload['messageId'] ?? 0) === (int) $assistant->id
                    && (bool) ($payload['preserveStream'] ?? false) === true;
            });

        $messages = collect($component->get('messages'));
        $this->assertTrue($messages->contains(fn (array $message) => (int) ($message['id'] ?? 0) === (int) $assistant->id));
        $this->assertTrue($messages->contains(fn (array $message) => $message['content'] === 'Jawaban SSE tetap memakai bubble live.'));
    }

    public function test_refresh_pending_chat_state_does_not_load_completed_inactive_conversation(): void
    {
        $user = User::factory()->create();
        $pendingConversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Inactive pending chat',
        ]);
        $activeConversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Active visible chat',
        ]);

        Message::create([
            'conversation_id' => $pendingConversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan di chat lain.',
        ]);
        Message::create([
            'conversation_id' => $activeConversation->id,
            'role' => 'user',
            'content' => 'Chat aktif tetap terlihat.',
        ]);
        Message::create([
            'conversation_id' => $activeConversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban chat aktif tetap terlihat.',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class, ['id' => $activeConversation->id])
            ->assertSet('currentConversationId', $activeConversation->id)
            ->assertSet('pendingConversationIds', [$pendingConversation->id]);

        $assistant = Message::create([
            'conversation_id' => $pendingConversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban chat lain tidak boleh muncul di chat aktif.',
        ]);
        $pendingConversation->touch();

        $component
            ->call('refreshPendingChatState', $assistant->id)
            ->assertSet('currentConversationId', $activeConversation->id)
            ->assertSet('pendingConversationIds', [])
            ->assertDispatched('chat-pending-state-updated', pendingConversationIds: [])
            ->assertDispatched('assistant-message-persisted', function (string $_event, array $payload) use ($pendingConversation, $assistant) {
                return (int) ($payload['conversationId'] ?? 0) === (int) $pendingConversation->id
                    && (int) ($payload['messageId'] ?? 0) === (int) $assistant->id;
            });

        $messages = collect($component->get('messages'));
        $this->assertTrue($messages->contains(fn (array $message) => $message['content'] === 'Chat aktif tetap terlihat.'));
        $this->assertTrue($messages->contains(fn (array $message) => $message['content'] === 'Jawaban chat aktif tetap terlihat.'));
        $this->assertFalse($messages->contains(fn (array $message) => $message['content'] === 'Jawaban chat lain tidak boleh muncul di chat aktif.'));
    }

    public function test_streaming_answer_uses_final_answer_bubble_style(): void
    {
        $user = User::factory()->create();
        $assistantBubbleBlade = file_get_contents(resource_path('views/livewire/chat/partials/assistant-answer-bubble.blade.php'));
        $this->assertIsString($assistantBubbleBlade);
        $this->assertStringContainsString('assistantTypewriter({', $assistantBubbleBlade);
        $this->assertStringContainsString('x-html="typewriterHtml"', $assistantBubbleBlade);
        $this->assertStringNotContainsString('typewriterEffect()', $assistantBubbleBlade);
        $this->assertStringNotContainsString('displayedContent', $assistantBubbleBlade);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertSee('x-show="streamingText !== \'\'"', false)
            ->assertSee('prose prose-p:my-1 prose-headings:my-2', false)
            ->assertSee('x-html="streamingHtml"', false)
            ->assertSee('x-if="sourcesVisible && sources && Array.isArray(sources) && sources.length > 0"', false)
            ->assertSee('x-if="streamedAssistantMessageId && streamingText !== \'\'"', false)
            ->assertSee('messageId: () => streamedAssistantMessageId', false)
            ->assertSee('html: () => streamingHtml', false)
            ->assertSee('resolvedMessageId()', false)
            ->assertDontSee('x-show="modelName"', false);
    }

    public function test_chat_history_groups_today_by_jakarta_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-09 10:00:00', 'Asia/Jakarta'));

        try {
            $user = User::factory()->create();
            $todayConversation = Conversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan hari ini',
            ]);
            $todayConversation->forceFill([
                'created_at' => Carbon::now('Asia/Jakarta'),
                'updated_at' => Carbon::now('Asia/Jakarta'),
            ])->save();

            $olderConversation = Conversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan kemarin',
            ]);
            $olderConversation->forceFill([
                'created_at' => Carbon::now('Asia/Jakarta')->subDay(),
                'updated_at' => Carbon::now('Asia/Jakarta')->subDay(),
            ])->save();

            $thirtyDayConversation = Conversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan sepuluh hari lalu',
            ]);
            $thirtyDayConversation->forceFill([
                'created_at' => Carbon::now('Asia/Jakarta')->subDays(10),
                'updated_at' => Carbon::now('Asia/Jakarta')->subDays(10),
            ])->save();

            $olderThanThirtyConversation = Conversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan empat puluh hari lalu',
            ]);
            $olderThanThirtyConversation->forceFill([
                'created_at' => Carbon::now('Asia/Jakarta')->subDays(40),
                'updated_at' => Carbon::now('Asia/Jakarta')->subDays(40),
            ])->save();

            $response = $this->actingAs($user)->get('/chat');

            $response
                ->assertOk()
                ->assertSee('Hari Ini', false)
                ->assertDontSee('Hari Ini ·', false)
                ->assertSee('Percakapan hari ini', false)
                ->assertSee('7 Hari Terakhir', false)
                ->assertDontSee('7 Hari Terakhir ·', false)
                ->assertSee('Percakapan kemarin', false)
                ->assertSee('30 Hari Terakhir', false)
                ->assertDontSee('30 Hari Terakhir ·', false)
                ->assertSee('Percakapan sepuluh hari lalu', false)
                ->assertSee('Lebih Lama', false)
                ->assertDontSee('Lebih Lama ·', false)
                ->assertSee('Percakapan empat puluh hari lalu', false)
                ->assertSee('Cari riwayat...', false)
                ->assertSee('Lihat semua', false)
                ->assertSee('historySectionKeys:', false)
                ->assertSee('id="chat-history-section-seven"', false)
                ->assertSee('id="chat-history-section-thirty"', false)
                ->assertSee('id="chat-history-section-older"', false)
                ->assertSee('style="display: none;"', false)
                ->assertSee('Tidak ada riwayat yang cocok.', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_google_drive_import_processing_document_is_not_added_as_ready_context(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'drive-import.pdf',
            'original_name' => 'drive-import.pdf',
            'file_path' => 'documents/'.$user->id.'/drive-import.pdf',
            'source_provider' => 'google_drive',
            'source_external_id' => 'drive-import-id',
            'source_synced_at' => now(),
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1234,
            'status' => 'processing',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('refreshDocumentsAfterGoogleDriveImport', $document->id)
            ->assertSet('conversationDocuments', [])
            ->assertDispatched('conversation-documents-preview');
    }

    public function test_load_conversations_uses_id_desc_as_tiebreaker_for_same_updated_at(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $sameTimestamp = now()->subMinutes(5);

        $conv1 = Conversation::create(['user_id' => $user->id, 'title' => 'Conv 1']);
        $conv2 = Conversation::create(['user_id' => $user->id, 'title' => 'Conv 2']);
        $conv3 = Conversation::create(['user_id' => $user->id, 'title' => 'Conv 3']);

        // Set all three to the same updated_at — without tiebreaker, order is non-deterministic
        Conversation::withoutTimestamps(function () use ($conv1, $conv2, $conv3, $sameTimestamp) {
            $conv1->forceFill(['updated_at' => $sameTimestamp])->save();
            $conv2->forceFill(['updated_at' => $sameTimestamp])->save();
            $conv3->forceFill(['updated_at' => $sameTimestamp])->save();
        });

        $component = Livewire::actingAs($user)->test(ChatIndex::class);
        $conversations = $component->get('conversations');

        // With id desc tiebreaker, conv3 (highest id) must come first
        $ids = $conversations->pluck('id')->values()->all();
        $this->assertSame([(int) $conv3->id, (int) $conv2->id, (int) $conv1->id], $ids,
            'Conversations with same updated_at must be ordered by id desc as tiebreaker');
    }

    // -------------------------------------------------------------------------
    // Double-submit / race-condition guard
    // -------------------------------------------------------------------------

    public function test_double_submit_on_conversation_with_pending_response_is_blocked(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Double-submit test',
        ]);

        // Simulate a user message already sent (pending AI response).
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan pertama',
            'created_at' => now(),
        ]);

        $component = Livewire::actingAs($user)->test(ChatIndex::class);
        $component->set('currentConversationId', $conversation->id);

        // Second submission while a pending response exists: must be blocked.
        $component->set('prompt', 'Pesan kedua')->call('sendMessage');

        // No additional user message should have been created.
        $this->assertDatabaseCount('messages', 1);

        // No AI job should be dispatched for the blocked submission.
        Queue::assertNotPushed(GenerateChatResponse::class);
    }

    public function test_first_submit_creates_message_and_dispatches_job_when_no_pending_response(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        // Conversation has no pending response (latest message is from assistant).
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Clean conversation',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban AI sebelumnya.',
            'created_at' => now()->subMinutes(5),
        ]);

        $component = Livewire::actingAs($user)->test(ChatIndex::class);
        $component->set('currentConversationId', $conversation->id);
        $component->set('prompt', 'Pertanyaan baru')->call('sendMessage');

        // User message must have been created.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pertanyaan baru',
        ]);

        // AI job must have been dispatched.
        Queue::assertPushed(GenerateChatResponse::class, function (GenerateChatResponse $job) use ($conversation, $user) {
            return $job->conversationId === (int) $conversation->id
                && $job->userId === (int) $user->id;
        });
    }
}
