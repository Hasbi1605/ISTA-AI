<?php

namespace App\Livewire\Chat;

use App\Jobs\GenerateChatResponse;
use App\Models\CloudStorageFile;
use App\Models\Conversation;
use App\Models\AIUsageEvent;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use App\Services\AIService;
use App\Services\Chat\ChatDocumentStateService;
use App\Services\ChatOrchestrationService;
use App\Services\CloudStorage\GoogleDriveService;
use App\Services\DocumentExportService;
use App\Services\DocumentLifecycleService;
use App\Support\SafeAssistantMarkdown;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ChatIndex extends Component
{
    use WithFileUploads;

    #[Url]
    public $q = '';

    #[Url]
    public string $tab = 'chat';

    public $prompt = '';

    public $currentConversationId;

    public $messages = [];

    public $conversations = [];

    public $pendingConversationIds = [];

    public $selectedDocuments = [];

    public $conversationDocuments = [];

    public $availableDocuments = [];

    public $showDocumentSelector = false;

    public $sources = [];

    public $showOlderChats = false;

    public $webSearchMode = false; // false = auto, true = force/on

    public $chatAttachment;

    public $isUploadingAttachment = false;

    public $attachmentUploadStatus = null;

    public $attachmentUploadMessage = '';

    public $uploadingAttachmentName = null;

    public $hasDocumentsInProgress = false;

    public $newMessageId = null;

    public $preservedStreamMessageId = null;

    public $streamingConversationId = null;

    // Maximum chats to show before "Show More"
    const MAX_VISIBLE_CHATS = 10;

    private const HISTORY_LOAD_LIMIT = 100;

    public function mount($id = null)
    {
        $this->loadConversations();
        $this->loadAvailableDocuments();

        if ($id) {
            $this->loadConversation($id);
        }

        if ($this->q) {
            $this->prompt = $this->q;
            $this->q = ''; // clear from URL so it doesn't persist
        }

        if (session()->has('pending_prompt')) {
            $this->prompt = session()->pull('pending_prompt');
        }
    }

    public function loadConversations()
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            $this->conversations = collect();
            $this->pendingConversationIds = [];
            $this->dispatchPendingConversationState();

            return;
        }

        $this->conversations = $user->conversations()
            ->with('latestMessage')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::HISTORY_LOAD_LIMIT)
            ->get();
        $this->pendingConversationIds = $this->conversations
            ->filter(fn (Conversation $conversation) => $this->conversationHasPendingResponse($conversation))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $this->dispatchPendingConversationState();
    }

    protected function chatDocumentStateService(): ChatDocumentStateService
    {
        return app(ChatDocumentStateService::class);
    }

    public function loadAvailableDocuments()
    {
        $state = $this->chatDocumentStateService()->loadAvailableDocuments((int) Auth::id());

        $this->availableDocuments = $state['documents'];
        $this->hasDocumentsInProgress = $state['has_documents_in_progress'];
    }

    public function loadConversation($id, bool $clearNewMessageId = true)
    {
        $conversation = Conversation::where('id', $id)
            ->where('user_id', Auth::id())
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc');
            }])
            ->firstOrFail();

        $this->currentConversationId = $conversation->id;
        $this->messages = $conversation->messages->toArray();
        $this->preservedStreamMessageId = null;
        $this->streamingConversationId = null;
        if ($clearNewMessageId) {
            $this->newMessageId = null;
        }
        $this->dispatch('conversation-activated', id: $conversation->id);
    }

    public function startNewChat()
    {
        $this->currentConversationId = null;
        $this->messages = [];
        $this->prompt = '';
        $this->selectedDocuments = [];
        $this->conversationDocuments = [];
        $this->sources = [];
        $this->newMessageId = null;
        $this->preservedStreamMessageId = null;
        $this->streamingConversationId = null;
        $this->attachmentUploadStatus = null;
        $this->attachmentUploadMessage = '';
        $this->uploadingAttachmentName = null;
        $this->dispatch('conversation-activated', id: null);
    }

    public function toggleDocumentSelector()
    {
        $this->showDocumentSelector = ! $this->showDocumentSelector;
    }

    public function toggleDocument($documentId)
    {
        $this->selectedDocuments = $this->chatDocumentStateService()->toggleDocument($this->selectedDocuments, $documentId);
    }

    public function selectAllDocuments()
    {
        $this->selectedDocuments = $this->chatDocumentStateService()->selectAllReadyDocuments((int) Auth::id());
    }

    public function toggleSelectAllDocuments()
    {
        $this->selectedDocuments = $this->chatDocumentStateService()->toggleSelectAllDocuments(
            $this->selectedDocuments,
            $this->chatDocumentStateService()->readyDocumentIds((int) Auth::id()),
        );
    }

    public function clearDocumentSelection()
    {
        $this->selectedDocuments = [];
    }

    public function updatedSelectedDocuments()
    {
        $this->selectedDocuments = $this->chatDocumentStateService()->filterSelectedDocuments(
            $this->selectedDocuments,
            $this->chatDocumentStateService()->readyDocumentIds((int) Auth::id()),
        );
    }

    public function addSelectedDocumentsToChat()
    {
        $this->conversationDocuments = $this->chatDocumentStateService()->addSelectedDocumentsToChat(
            $this->selectedDocuments,
            $this->chatDocumentStateService()->readyDocumentIds((int) Auth::id()),
        );
    }

    public function clearConversationDocuments()
    {
        $this->conversationDocuments = [];
    }

    public function deleteDocument($documentId, DocumentLifecycleService $documentLifecycleService)
    {
        $document = Document::where('id', $documentId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $document) {
            session()->flash('error', 'Dokumen tidak ditemukan atau sudah dihapus.');

            return;
        }

        try {
            $documentLifecycleService->deleteDocument($document);

            $stateService = $this->chatDocumentStateService();
            $this->selectedDocuments = $stateService->removeDocumentIds($this->selectedDocuments, (int) $documentId);
            $this->conversationDocuments = $stateService->removeDocumentIds($this->conversationDocuments, (int) $documentId);

            $this->loadAvailableDocuments();
            session()->flash('message', 'Dokumen berhasil dihapus.');
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', UserFacingError::message($e, 'Gagal menghapus dokumen. Silakan coba lagi.'));
        }
    }

    public function deleteSelectedDocuments(DocumentLifecycleService $documentLifecycleService)
    {
        $documentIds = array_map('intval', $this->selectedDocuments);

        if (empty($documentIds)) {
            session()->flash('error', 'Pilih dokumen terlebih dahulu.');

            return;
        }

        $documents = Document::where('user_id', Auth::id())
            ->whereIn('id', $documentIds)
            ->get();

        try {
            $documentLifecycleService->deleteDocuments($documents);

            $this->selectedDocuments = [];
            $this->conversationDocuments = $this->chatDocumentStateService()->removeDocumentIds(
                $this->conversationDocuments,
                $documentIds,
            );

            $this->loadAvailableDocuments();
            session()->flash('message', 'Dokumen terpilih berhasil dihapus.');
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', UserFacingError::message($e, 'Gagal menghapus dokumen terpilih. Silakan coba lagi.'));
        }
    }

    public function reprocessDocument(int $documentId, DocumentLifecycleService $documentLifecycleService): void
    {
        if ($this->isRateLimited('reprocessDocument:'.$documentId, 3, 300)) {
            session()->flash('error', 'Terlalu banyak percobaan proses ulang untuk dokumen ini. Coba lagi beberapa menit lagi.');

            return;
        }

        $document = Document::where('id', $documentId)->where('user_id', Auth::id())->first();

        if (! $document) {
            session()->flash('error', 'Dokumen tidak ditemukan atau bukan milik Anda.');

            return;
        }

        if ($document->status !== 'error') {
            session()->flash('error', 'Hanya dokumen yang gagal diproses yang dapat dicoba ulang.');

            return;
        }

        try {
            $document->forceFill([
                'status' => 'pending',
                'preview_status' => Document::PREVIEW_STATUS_PENDING,
                'preview_html_path' => null,
            ])->save();
            $documentLifecycleService->dispatchProcessing($document);
            $this->selectedDocuments = $this->chatDocumentStateService()->removeDocumentIds($this->selectedDocuments, (int) $documentId);
            $this->conversationDocuments = $this->chatDocumentStateService()->removeDocumentIds($this->conversationDocuments, (int) $documentId);
            $this->loadAvailableDocuments();
            session()->flash('message', 'Dokumen dijadwalkan ulang untuk diproses. Jika gagal lagi, unggah ulang file sumber.');
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', UserFacingError::message($e, 'Gagal menjadwalkan proses ulang dokumen. Coba lagi sebentar.'));
        }
    }

    public function removeConversationDocument($documentId)
    {
        $this->conversationDocuments = $this->chatDocumentStateService()->removeDocumentIds(
            $this->conversationDocuments,
            (int) $documentId,
        );

        $this->selectedDocuments = $this->chatDocumentStateService()->removeDocumentIds(
            $this->selectedDocuments,
            (int) $documentId,
        );
    }

    public function toggleOlderChats()
    {
        $this->showOlderChats = ! $this->showOlderChats;
    }

    public function toggleWebSearch()
    {
        $this->webSearchMode = ! $this->webSearchMode;
    }

    public function updatedChatAttachment()
    {
        if (! $this->chatAttachment) {
            return;
        }

        $this->attachmentUploadStatus = null;
        $this->attachmentUploadMessage = '';
        $this->uploadChatAttachment(app(DocumentLifecycleService::class));
    }

    public function uploadChatAttachment(DocumentLifecycleService $documentLifecycleService)
    {
        try {
            $this->enforceRateLimit('uploadChatAttachment', 5, 60, 'Terlalu banyak upload dokumen. Coba lagi sebentar.');
            $this->validate([
                'chatAttachment' => [
                    'required',
                    'file',
                    'mimes:'.implode(',', Document::attachmentFileExtensions()),
                    'max:51200',
                ],
            ], [
                'chatAttachment.required' => 'Pilih file dokumen terlebih dahulu.',
                'chatAttachment.file' => 'Lampiran chat harus berupa file dokumen.',
                'chatAttachment.mimes' => 'Lampiran chat harus berupa file PDF, DOCX, XLSX, atau CSV.',
                'chatAttachment.max' => 'Ukuran lampiran chat tidak boleh lebih dari 50 MB.',
            ], [
                'chatAttachment' => 'lampiran chat',
            ]);

            $this->isUploadingAttachment = true;
            $this->uploadingAttachmentName = $this->chatAttachment->getClientOriginalName();

            $documentLifecycleService->uploadDocument($this->chatAttachment, Auth::id());

            session()->flash('message', 'Dokumen berhasil diunggah dan sedang diproses.');
            $this->attachmentUploadStatus = 'success';
            $this->attachmentUploadMessage = 'Upload berhasil. Dokumen sedang diproses.';
            $this->loadAvailableDocuments();
        } catch (ValidationException $e) {
            $errors = $e->validator->errors();
            $message = $errors->first('file') ?: ($errors->first('chatAttachment') ?: 'Upload gagal. Periksa format file dan coba lagi.');
            session()->flash('error', $message);
            $this->attachmentUploadStatus = 'error';
            $this->attachmentUploadMessage = $message;
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', UserFacingError::message($e, 'Gagal mengunggah dokumen. Periksa koneksi atau coba lagi.'));
            $this->attachmentUploadStatus = 'error';
            $this->attachmentUploadMessage = 'Upload gagal. Periksa format file dan coba lagi.';
        } finally {
            $this->isUploadingAttachment = false;
            $this->uploadingAttachmentName = null;
            $this->reset('chatAttachment');
        }
    }

    #[On('google-drive-document-imported')]
    public function refreshDocumentsAfterGoogleDriveImport(?int $documentId = null): void
    {
        $this->loadAvailableDocuments();

        if ($documentId !== null) {
            $document = Document::query()
                ->whereKey((int) $documentId)
                ->where('user_id', Auth::id())
                ->first();

            if ($document) {
                $this->dispatch('conversation-documents-preview',
                    ids: $this->conversationDocuments,
                    documents: [[
                        'id' => (int) $document->id,
                        'name' => (string) $document->original_name,
                        'extension' => (string) $document->extension,
                        'status' => (string) $document->status,
                    ]],
                );
            }
        }

        $this->attachmentUploadStatus = 'success';
        $this->attachmentUploadMessage = 'File Google Drive berhasil ditambahkan dan sedang diproses.';

        session()->flash('message', 'File Google Drive berhasil ditambahkan dan sedang diproses.');
    }

    /**
     * Upload a persisted assistant answer to the office Google Drive in the requested format.
     *
     * @return array{ok: bool, message?: string, file_name?: string, web_view_link?: ?string, folder_external_id?: ?string}
     */
    public function saveAnswerToGoogleDrive(int $messageId, string $targetFormat): array
    {
        $userId = Auth::id();
        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();

        if ($userId === null) {
            return [
                'ok' => false,
                'message' => 'Anda harus login terlebih dahulu.',
            ];
        }

        $targetFormat = $this->normalizeDriveExportFormat($targetFormat);

        if ($targetFormat === null) {
            return [
                'ok' => false,
                'message' => 'Format upload Google Drive tidak didukung.',
            ];
        }

        if ($this->isRateLimited('saveAnswerToGoogleDrive', 10, 60)) {
            return [
                'ok' => false,
                'message' => 'Terlalu banyak permintaan ekspor Google Drive. Coba lagi sebentar.',
            ];
        }

        $message = Message::query()
            ->whereKey($messageId)
            ->where('role', 'assistant')
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $userId))
            ->first();

        if ($message === null) {
            return [
                'ok' => false,
                'message' => 'Jawaban AI tidak ditemukan.',
            ];
        }

        try {
            $contentHtml = app(SafeAssistantMarkdown::class)->toHtml($message->content);

            if ($this->formatRequiresTable($targetFormat) && ! $this->contentHtmlContainsTable($contentHtml)) {
                $usageEvents->failed(
                    feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_EXPORT,
                    userId: (int) $userId,
                    metadata: [
                        'message_id' => (int) $messageId,
                        'target_format' => $targetFormat,
                        'reason' => 'format_requires_table',
                    ],
                    requestId: $requestId,
                    latencyMs: $usageEvents->latencyMsSince($startedAt),
                    errorCode: 'format_requires_table',
                    subject: $message,
                );

                return [
                    'ok' => false,
                    'message' => 'Format spreadsheet hanya tersedia untuk jawaban AI yang berisi tabel.',
                ];
            }

            $exportService = app(DocumentExportService::class);
            $artifact = $exportService->exportContent(
                $contentHtml,
                $targetFormat,
                'ista-ai-jawaban-'.$message->id,
            );

            $tempRelativePath = 'tmp/cloud/google-drive/'.Str::uuid().'.'.$targetFormat;
            Storage::disk('local')->put($tempRelativePath, $artifact['body']);
            $tempAbsolutePath = Storage::disk('local')->path($tempRelativePath);

            try {
                $upload = app(GoogleDriveService::class)->uploadFromPath(
                    $tempAbsolutePath,
                    $artifact['file_name'],
                    $artifact['content_type'],
                    null,
                );

                CloudStorageFile::updateOrCreate(
                    [
                        'user_id' => (int) $userId,
                        'provider' => 'google_drive',
                        'external_id' => $upload['external_id'],
                    ],
                    [
                        'direction' => CloudStorageFile::DIRECTION_EXPORT,
                        'local_type' => Message::class,
                        'local_id' => $message->id,
                        'name' => $upload['name'],
                        'mime_type' => $upload['mime_type'],
                        'web_view_link' => $upload['web_view_link'],
                        'folder_external_id' => $upload['folder_external_id'],
                        'size_bytes' => $upload['size_bytes'],
                        'synced_at' => now(),
                    ]
                );
            } finally {
                Storage::disk('local')->delete($tempRelativePath);
            }

            $usageEvents->completed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_EXPORT,
                userId: (int) $userId,
                metadata: [
                    'message_id' => (int) $messageId,
                    'target_format' => $targetFormat,
                    'provider' => 'google_drive',
                    'mime_type' => $upload['mime_type'] ?? null,
                    'size_bytes' => $upload['size_bytes'] ?? null,
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                subject: $message,
            );

            return [
                'ok' => true,
                'file_name' => $upload['name'],
                'web_view_link' => $upload['web_view_link'],
                'folder_external_id' => $upload['folder_external_id'],
            ];
        } catch (\Throwable $e) {
            report($e);

            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_EXPORT,
                userId: (int) $userId,
                metadata: [
                    'message_id' => (int) $messageId,
                    'target_format' => $targetFormat,
                    'provider' => 'google_drive',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'exception',
                subject: $message,
            );

            return [
                'ok' => false,
                'message' => UserFacingError::message($e, 'Upload ke Google Drive gagal. Coba lagi atau hubungi admin bila berulang.'),
            ];
        }
    }

    public function deleteConversation($id)
    {
        $conversation = Conversation::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($conversation) {
            DB::transaction(function () use ($conversation) {
                $messageIds = $conversation->messages()->pluck('id');

                if ($messageIds->isNotEmpty()) {
                    CloudStorageFile::query()
                        ->where('local_type', Message::class)
                        ->whereIn('local_id', $messageIds)
                        ->delete();
                }

                $conversation->delete();
            });

            // If we deleted the current conversation, reset
            if ($this->currentConversationId == $id) {
                $this->startNewChat();
            }

            // Reload conversations
            $this->loadConversations();
        }
    }

    public function sendMessage(AIService $aiService, ?string $prompt = null, ?ChatOrchestrationService $orchestrator = null, ?AIUsageEventService $usageEvents = null)
    {
        $orchestrator = $orchestrator ?? app(ChatOrchestrationService::class);
        $usageEvents = $usageEvents ?? app(AIUsageEventService::class);

        if ($prompt !== null) {
            $this->prompt = $prompt;
        }

        $this->newMessageId = null;
        $this->preservedStreamMessageId = null;
        $this->streamingConversationId = null;

        $this->validate([
            'prompt' => 'required|string|min:1|max:8000',
        ]);

        $this->enforceRateLimit('sendMessage', 10, 60, 'Terlalu banyak mengirim pesan. Coba lagi sebentar.');
        set_time_limit(120);

        $this->currentConversationId = $orchestrator->createConversationIfNeeded(
            $this->currentConversationId,
            $this->prompt
        );

        $conversationIdForRequest = (int) $this->currentConversationId;

        // ── Atomic double-submit guard ────────────────────────────────────────
        // Wrap the pending-response check and user-message insert in a single
        // DB transaction with a row lock on the conversation. This prevents two
        // concurrent requests for the same conversation from both passing the
        // pending check and both inserting a user message + dispatching a job.
        $conversationDocuments = $orchestrator->normalizeDocumentIds($this->conversationDocuments);

        $userMessageArray = DB::transaction(function () use ($conversationIdForRequest, $orchestrator, $conversationDocuments) {
            $activeConversation = Conversation::query()
                ->lockForUpdate()
                ->find($conversationIdForRequest);

            if ($activeConversation === null) {
                return null;
            }

            $activeConversation->load('latestMessage');

            if ($this->conversationHasPendingResponse($activeConversation)) {
                return null;
            }

            return $orchestrator->saveUserMessage($conversationIdForRequest, $this->prompt, $conversationDocuments);
        });

        if ($userMessageArray === null) {
            $this->dispatch('user-message-rejected', conversationId: $conversationIdForRequest, reason: 'pending_response');

            return [
                'conversationId' => $conversationIdForRequest,
                'messageId' => null,
                'rejected' => true,
                'reason' => 'pending_response',
            ];
        }
        $this->messages[] = $userMessageArray;
        $this->dispatch('user-message-acked', conversationId: $conversationIdForRequest, messageId: $userMessageArray['id'] ?? null);
        $this->prompt = '';
        $this->sources = [];

        $history = $orchestrator->buildHistory($this->messages);
        $webSearchMode = (bool) $this->webSearchMode;

        Conversation::query()
            ->whereKey($conversationIdForRequest)
            ->where('user_id', Auth::id())
            ->touch();

        $this->loadConversations();
        $this->dispatch('conversation-activated', id: $conversationIdForRequest);

        // Create stream intent as early as possible so the background job
        // fallback can defer while EventSource is still connecting.
        $orchestrator->createStreamIntent($conversationIdForRequest);
        $this->streamingConversationId = $conversationIdForRequest;

        $requestId = $usageEvents->newRequestId();
        $hasDocumentContext = ! empty($conversationDocuments);
        $feature = $this->resolveChatFeature($webSearchMode, $hasDocumentContext);

        $usageEvents->started(
            feature: $feature,
            userId: (int) Auth::id(),
            metadata: [
                'conversation_id' => $conversationIdForRequest,
                'message_id' => $userMessageArray['id'] ?? null,
                'document_count' => count($conversationDocuments),
                'has_documents' => $hasDocumentContext,
                'web_search_mode' => $webSearchMode,
                'history_message_count' => count($history),
                'channel' => 'livewire',
            ],
            requestId: $requestId,
        );

        GenerateChatResponse::dispatch(
            $conversationIdForRequest,
            (int) Auth::id(),
            $history,
            $conversationDocuments,
            $webSearchMode,
            $requestId,
        );

        return [
            'conversationId' => $conversationIdForRequest,
            'messageId' => $userMessageArray['id'] ?? null,
            'requestId' => $requestId,
        ];
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

    public function markStreamFailed(int $conversationId): void
    {
        if ((int) $this->streamingConversationId === (int) $conversationId) {
            $this->streamingConversationId = null;
        }
    }

    public function refreshPendingChatState(?int $alreadyStreamedMessageId = null, bool $preserveActiveStream = false, ?int $streamConversationId = null): void
    {
        $alreadyStreamedMessageId = $alreadyStreamedMessageId !== null && $alreadyStreamedMessageId > 0
            ? $alreadyStreamedMessageId
            : null;
        $streamConversationId = $streamConversationId !== null && $streamConversationId > 0
            ? $streamConversationId
            : null;
        $previousPendingIds = collect($this->pendingConversationIds)
            ->map(fn ($id) => (int) $id)
            ->values();
        $activeConversationId = $this->currentConversationId ? (int) $this->currentConversationId : null;
        $streamMatchesActiveConversation = $streamConversationId === null
            || ($activeConversationId !== null && (int) $streamConversationId === $activeConversationId);
        $preserveActiveStream = $preserveActiveStream
            && $alreadyStreamedMessageId !== null
            && $streamMatchesActiveConversation;

        $this->loadConversations();

        $currentPendingIds = collect($this->pendingConversationIds)
            ->map(fn ($id) => (int) $id)
            ->values();
        $suppressActiveStreamRefresh = $activeConversationId !== null
            && $alreadyStreamedMessageId === null
            && (int) ($this->streamingConversationId ?? 0) === $activeConversationId;

        if ($suppressActiveStreamRefresh && ! $currentPendingIds->contains($activeConversationId)) {
            $currentPendingIds = $currentPendingIds
                ->push($activeConversationId)
                ->unique()
                ->values();
            $this->pendingConversationIds = $currentPendingIds->all();
            $this->dispatchPendingConversationState();
        }

        $completedIds = $previousPendingIds->diff($currentPendingIds)->values();
        $preservedActiveStream = false;

        if (
            $activeConversationId !== null
            && ! $suppressActiveStreamRefresh
            && ($previousPendingIds->contains($activeConversationId) || $currentPendingIds->contains($activeConversationId) || $alreadyStreamedMessageId !== null)
        ) {
            $latestAssistant = Message::query()
                ->where('conversation_id', $activeConversationId)
                ->where('role', 'assistant')
                ->latest('id')
                ->first(['id', 'created_at']);
            $latestAssistantId = $latestAssistant?->id;
            $shouldPreserveActiveStream = $preserveActiveStream
                && $latestAssistantId
                && (int) $latestAssistantId === $alreadyStreamedMessageId;

            if ($shouldPreserveActiveStream) {
                $this->newMessageId = null;
                $this->preservedStreamMessageId = (int) $latestAssistantId;
                $this->streamingConversationId = null;
                $preservedActiveStream = true;
                $this->syncActiveAssistantMessageIntoState($activeConversationId, (int) $latestAssistantId);
            } else {
                $this->preservedStreamMessageId = null;

                if (
                    $completedIds->contains($activeConversationId)
                    && $latestAssistantId
                    && (int) $latestAssistantId !== $alreadyStreamedMessageId
                ) {
                    $this->newMessageId = (int) $latestAssistantId;
                }

                $this->loadConversation($activeConversationId, clearNewMessageId: false);
            }
        }

        $dispatchConversationIds = $completedIds;
        if ($preservedActiveStream && $activeConversationId !== null) {
            $dispatchConversationIds = $dispatchConversationIds
                ->push($activeConversationId)
                ->unique()
                ->values();
        }

        foreach ($dispatchConversationIds as $completedConversationId) {
            if ($suppressActiveStreamRefresh && $activeConversationId !== null && (int) $completedConversationId === $activeConversationId) {
                continue;
            }

            $latestAssistant = Message::query()
                ->where('conversation_id', (int) $completedConversationId)
                ->where('role', 'assistant')
                ->latest('id')
                ->first(['id', 'created_at']);
            $latestAssistantId = $latestAssistant?->id;
            $shouldPreserveCompletedStream = $preserveActiveStream
                && $activeConversationId !== null
                && (int) $completedConversationId === $activeConversationId
                && $latestAssistantId
                && (int) $latestAssistantId === $alreadyStreamedMessageId;

            $this->dispatch(
                'assistant-message-persisted',
                conversationId: (int) $completedConversationId,
                messageId: $latestAssistantId ? (int) $latestAssistantId : null,
                createdAt: $this->formatMessageCreatedAtForBrowser($latestAssistant),
                preserveStream: $shouldPreserveCompletedStream,
            );
        }
    }

    public function render()
    {
        return view('livewire.chat.chat-index', [
            'googleDriveUploadAvailable' => app(GoogleDriveService::class)->canUploadWithConfiguredAccount(),
        ]);
    }

    private function conversationHasPendingResponse(Conversation $conversation): bool
    {
        $latestMessage = $conversation->latestMessage;

        if (! $latestMessage || $latestMessage->role !== 'user') {
            return false;
        }

        $createdAt = $latestMessage->created_at;

        return $createdAt === null || $createdAt->greaterThan(now()->subMinutes(30));
    }

    private function syncActiveAssistantMessageIntoState(int $conversationId, int $assistantId): void
    {
        $assistant = Message::query()
            ->whereKey($assistantId)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->first();

        if (! $assistant) {
            return;
        }

        $this->messages = collect($this->messages)
            ->reject(fn (array $message) => (int) ($message['id'] ?? 0) === $assistantId)
            ->push($assistant->toArray())
            ->sortBy(fn (array $message) => (int) ($message['id'] ?? 0))
            ->values()
            ->all();
    }

    private function formatMessageCreatedAtForBrowser(?Message $message): ?string
    {
        return $message?->created_at?->timezone('Asia/Jakarta')->toIso8601String();
    }

    private function dispatchPendingConversationState(): void
    {
        $this->dispatch('chat-pending-state-updated', pendingConversationIds: array_values(array_map(
            fn ($id) => (int) $id,
            $this->pendingConversationIds,
        )));
    }

    private function normalizeDriveExportFormat(string $targetFormat): ?string
    {
        $normalized = strtolower(trim($targetFormat));

        return in_array($normalized, ['pdf', 'docx', 'xlsx', 'csv'], true)
            ? $normalized
            : null;
    }

    private function formatRequiresTable(string $targetFormat): bool
    {
        return in_array(strtolower(trim($targetFormat)), ['xlsx', 'csv'], true);
    }

    private function contentHtmlContainsTable(string $contentHtml): bool
    {
        return str_contains(Str::lower($contentHtml), '<table');
    }

    private function enforceRateLimit(string $action, int $maxAttempts, int $decaySeconds = 60, ?string $message = null): void
    {
        $key = $this->rateLimitKey($action);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'rate_limit' => $message ?? 'Terlalu banyak permintaan. Silakan coba lagi sebentar.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function isRateLimited(string $action, int $maxAttempts, int $decaySeconds = 60): bool
    {
        $key = $this->rateLimitKey($action);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return true;
        }

        RateLimiter::hit($key, $decaySeconds);

        return false;
    }

    private function rateLimitKey(string $action): string
    {
        $userId = Auth::id();
        $ip = request()?->ip() ?? 'unknown';
        $userPart = $userId ? 'user-'.$userId : 'guest';

        return implode(':', [static::class, $action, $userPart, $ip]);
    }
}
