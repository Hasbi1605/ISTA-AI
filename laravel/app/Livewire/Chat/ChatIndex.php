<?php

namespace App\Livewire\Chat;

use App\Jobs\ProcessDocument;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Document;
use App\Services\AIService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ChatIndex extends Component
{
    use WithFileUploads;

    public $prompt = '';
    public $currentConversationId;
    public $messages = [];
    public $conversations = [];
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

    // Maximum chats to show before "Show More"
    const MAX_VISIBLE_CHATS = 10;

    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function mount($id = null)
    {
        $this->loadConversations();
        $this->loadAvailableDocuments();

        if ($id) {
            $this->loadConversation($id);
        }
    }

    public function loadConversations()
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (!$user) {
            $this->conversations = collect();
            return;
        }

        $this->conversations = $user->conversations()->orderBy('updated_at', 'desc')->get();
    }

    public function loadAvailableDocuments()
    {
        $documents = Document::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        $this->hasDocumentsInProgress = $documents->contains(function (Document $document) {
            return in_array($document->status, ['pending', 'processing'], true);
        });

        $this->availableDocuments = $documents;
    }

    protected function getReadyDocumentIds(): array
    {
        return Document::where('user_id', Auth::id())
            ->where('status', 'ready')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    public function loadConversation($id)
    {
        $conversation = Conversation::where('id', $id)
            ->where('user_id', Auth::id())
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at', 'asc');
            }])
            ->firstOrFail();

        $this->currentConversationId = $conversation->id;
        $this->messages = $conversation->messages->toArray();
    }

    public function startNewChat()
    {
        $this->currentConversationId = null;
        $this->messages = [];
        $this->prompt = '';
        $this->selectedDocuments = [];
        $this->conversationDocuments = [];
        $this->sources = [];
        $this->attachmentUploadStatus = null;
        $this->attachmentUploadMessage = '';
        $this->uploadingAttachmentName = null;
    }

    public function toggleDocumentSelector()
    {
        $this->showDocumentSelector = !$this->showDocumentSelector;
    }

    public function toggleDocument($documentId)
    {
        if (in_array($documentId, $this->selectedDocuments)) {
            $this->selectedDocuments = array_values(array_filter($this->selectedDocuments, function ($id) use ($documentId) {
                return $id != $documentId;
            }));
        } else {
            $this->selectedDocuments[] = $documentId;
        }
    }

    public function selectAllDocuments()
    {
        $this->selectedDocuments = $this->getReadyDocumentIds();
    }

    public function toggleSelectAllDocuments()
    {
        $allDocumentIds = $this->getReadyDocumentIds();

        $selectedIds = array_map('intval', $this->selectedDocuments);
        sort($allDocumentIds);
        sort($selectedIds);

        if (!empty($allDocumentIds) && $selectedIds === $allDocumentIds) {
            $this->selectedDocuments = [];
            return;
        }

        $this->selectedDocuments = $allDocumentIds;
    }

    public function clearDocumentSelection()
    {
        $this->selectedDocuments = [];
    }

    public function updatedSelectedDocuments()
    {
        $availableIds = $this->getReadyDocumentIds();

        $availableMap = array_flip($availableIds);
        $this->selectedDocuments = array_values(array_filter(array_map('intval', $this->selectedDocuments), function ($id) use ($availableMap) {
            return isset($availableMap[$id]);
        }));
    }

    public function addSelectedDocumentsToChat()
    {
        $readyMap = array_flip($this->getReadyDocumentIds());

        $this->conversationDocuments = array_values(array_filter(array_unique(array_map('intval', $this->selectedDocuments)), function ($id) use ($readyMap) {
            return isset($readyMap[$id]);
        }));
    }

    public function clearConversationDocuments()
    {
        $this->conversationDocuments = [];
    }

    public function deleteDocument($documentId)
    {
        $document = Document::where('id', $documentId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$document) {
            return;
        }

        if ($document->file_path && Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }

        $document->delete();

        $this->selectedDocuments = array_values(array_filter($this->selectedDocuments, function ($id) use ($documentId) {
            return (int) $id !== (int) $documentId;
        }));

        $this->conversationDocuments = array_values(array_filter($this->conversationDocuments, function ($id) use ($documentId) {
            return (int) $id !== (int) $documentId;
        }));

        $this->loadAvailableDocuments();
    }

    public function deleteSelectedDocuments()
    {
        $documentIds = array_map('intval', $this->selectedDocuments);

        if (empty($documentIds)) {
            return;
        }

        $documents = Document::where('user_id', Auth::id())
            ->whereIn('id', $documentIds)
            ->get();

        foreach ($documents as $document) {
            if ($document->file_path && Storage::exists($document->file_path)) {
                Storage::delete($document->file_path);
            }
            $document->delete();
        }

        $this->selectedDocuments = [];
        $this->conversationDocuments = array_values(array_filter($this->conversationDocuments, function ($id) use ($documentIds) {
            return !in_array((int) $id, $documentIds, true);
        }));

        $this->loadAvailableDocuments();
    }

    public function removeConversationDocument($documentId)
    {
        $this->conversationDocuments = array_values(array_filter($this->conversationDocuments, function ($id) use ($documentId) {
            return (int) $id !== (int) $documentId;
        }));

        $this->selectedDocuments = array_values(array_filter($this->selectedDocuments, function ($id) use ($documentId) {
            return (int) $id !== (int) $documentId;
        }));
    }

    public function toggleOlderChats()
    {
        $this->showOlderChats = !$this->showOlderChats;
    }

    public function toggleWebSearch()
    {
        $this->webSearchMode = !$this->webSearchMode;
    }

    public function updatedChatAttachment()
    {
        if (!$this->chatAttachment) {
            return;
        }

        $this->attachmentUploadStatus = null;
        $this->attachmentUploadMessage = '';
        $this->uploadChatAttachment();
    }

    public function uploadChatAttachment()
    {
        try {
            $this->validate([
                'chatAttachment' => [
                    'required',
                    'file',
                    'mimes:pdf,docx,xlsx',
                    'mimetypes:' . implode(',', self::ALLOWED_ATTACHMENT_MIME_TYPES),
                    'max:51200',
                ],
            ]);

            $originalName = $this->chatAttachment->getClientOriginalName();
            $detectedMimeType = (string) $this->chatAttachment->getMimeType();

            if (!in_array($detectedMimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
                throw ValidationException::withMessages([
                    'chatAttachment' => 'Tipe MIME file tidak valid. Gunakan PDF, DOCX, atau XLSX.',
                ]);
            }

            $duplicateExists = Document::where('user_id', Auth::id())
                ->where('original_name', $originalName)
                ->exists();

            if ($duplicateExists) {
                $this->attachmentUploadStatus = 'error';
                $this->attachmentUploadMessage = 'File dengan nama yang sama sudah pernah diunggah.';
                session()->flash('error', $this->attachmentUploadMessage);
                $this->reset('chatAttachment');
                return;
            }

            $documentCount = Document::where('user_id', Auth::id())->count();
            if ($documentCount >= 10) {
                session()->flash('error', 'Limit kuota dokumen tercapai (Maksimal 10 dokumen).');
                $this->attachmentUploadStatus = 'error';
                $this->attachmentUploadMessage = 'Limit kuota dokumen tercapai (Maksimal 10 dokumen).';
                $this->reset('chatAttachment');
                return;
            }

            $this->isUploadingAttachment = true;

            $this->uploadingAttachmentName = $originalName;
            $filename = time() . '_' . $this->chatAttachment->hashName();
            $filePath = $this->chatAttachment->storeAs('documents/' . Auth::id(), $filename);

            $document = Document::create([
                'user_id' => Auth::id(),
                'filename' => $filename,
                'original_name' => $originalName,
                'file_path' => $filePath,
                'mime_type' => $detectedMimeType,
                'file_size_bytes' => $this->chatAttachment->getSize(),
                'status' => 'pending',
            ]);

            ProcessDocument::dispatch($document);

            session()->flash('message', 'Dokumen berhasil diunggah dan sedang diproses.');
            $this->attachmentUploadStatus = 'success';
            $this->attachmentUploadMessage = 'Upload berhasil. Dokumen sedang diproses.';
            $this->loadAvailableDocuments();
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('chatAttachment') ?: 'Upload gagal. Periksa format file dan coba lagi.';
            session()->flash('error', $message);
            $this->attachmentUploadStatus = 'error';
            $this->attachmentUploadMessage = $message;
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal mengunggah dokumen: ' . $e->getMessage());
            $this->attachmentUploadStatus = 'error';
            $this->attachmentUploadMessage = 'Upload gagal. Periksa format file dan coba lagi.';
        } finally {
            $this->isUploadingAttachment = false;
            $this->uploadingAttachmentName = null;
            $this->reset('chatAttachment');
        }
    }

    public function deleteConversation($id)
    {
        $conversation = Conversation::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($conversation) {
            // Delete all messages first
            $conversation->messages()->delete();
            // Delete the conversation
            $conversation->delete();

            // If we deleted the current conversation, reset
            if ($this->currentConversationId == $id) {
                $this->startNewChat();
            }

            // Reload conversations
            $this->loadConversations();
        }
    }

    public function sendMessage(?string $prompt = null, ?AIService $aiService = null)
    {
        $aiService = $aiService ?? app(AIService::class);

        if ($prompt !== null) {
            $this->prompt = $prompt;
        }

        // Mencegah PHP kill process (Time Limit Exceeded) akibat lamanya process LLM
        set_time_limit(120);

        // Reset pointer typewriter agar hanya pesan assistant terbaru yang dianimasikan.
        $this->newMessageId = null;

        $this->validate([
            'prompt' => 'required|string|min:1',
        ]);

        // 1. Ensure conversation exists
        if (!$this->currentConversationId) {
            $conversation = Conversation::create([
                'user_id' => Auth::id(),
                'title' => substr($this->prompt, 0, 50) . '...'
            ]);
            $this->currentConversationId = $conversation->id;
        }

        // 2. Save User Message
        $userMessage = Message::create([
            'conversation_id' => $this->currentConversationId,
            'role' => 'user',
            'content' => $this->prompt
        ]);

        $this->messages[] = $userMessage->toArray();
        $this->dispatch('user-message-acked');
        $this->prompt = '';
        $this->sources = [];

        // 3. Prepare full history for AI
        $history = [
            ['role' => 'system', 'content' => "Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu."]
        ];

        foreach ($this->messages as $msg) {
            $history[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        // 4. Handle Streaming Response
        $fullResponse = "";
        $modelName = "AI";

        // Push a placeholder assistant message for streaming
        $this->stream('assistant-output', "", true);

        // Get document filenames for RAG mode
        $documentFilenames = null;
        if (!empty($this->conversationDocuments)) {
            $documentFilenames = Document::whereIn('id', $this->conversationDocuments)
                ->pluck('original_name')
                ->toArray();
        }

        $hasDocumentContext = !empty($documentFilenames);
        $sourcePolicy = $hasDocumentContext ? 'document_context' : 'hybrid_realtime_auto';
        $allowAutoRealtimeWeb = !$hasDocumentContext;

        // Fetch stream from AIService
        foreach (
            $aiService->sendChat(
                $history,
                $documentFilenames,
                (string) Auth::id(),
                $this->webSearchMode,
                $sourcePolicy,
                $allowAutoRealtimeWeb
            ) as $chunk
        ) {
            // Parse model indicator from the first chunk
            if (preg_match('/\[MODEL:(.+?)\]\n?/', $chunk, $matches)) {
                $modelName = $matches[1];
                $chunk = preg_replace('/\[MODEL:.+?\]\n?/', '', $chunk);
                $this->stream('model-name', $modelName);
            }

            // Parse sources if present (match multiline JSON, use /s modifier for multiline)
            if (preg_match('/\[SOURCES:(\[.+?\])\]/s', $chunk, $matches)) {
                $sourcesJson = $matches[1];
                $parsedSources = json_decode($sourcesJson, true);
                if ($parsedSources) {
                    $this->sources = $parsedSources;
                    // Emit to Alpine.js for real-time display
                    $this->dispatch('assistant-sources', $this->sources);
                }
                // Remove sources from chunk
                $chunk = preg_replace('/\[SOURCES:\[.+?\]\]/s', '', $chunk);
            }

            $chunk = $this->sanitizeAssistantOutput((string) $chunk);

            if ($chunk !== '') {
                $fullResponse .= $chunk;
                $this->stream('assistant-output', $chunk);
            }
        }

        // 5. Finalize: Save AI Message to DB (remove sources metadata completely)
        // Clean up any remaining source markers
        $cleanContent = preg_replace('/\[SOURCES:\[.+?\]\]/s', '', $fullResponse);
        $cleanContent = $this->sanitizeAssistantOutput((string) $cleanContent);
        $cleanContent = trim($cleanContent);

        $assistantMsg = Message::create([
            'conversation_id' => $this->currentConversationId,
            'role' => 'assistant',
            'content' => $cleanContent
        ]);

        $this->newMessageId = $assistantMsg->id;

        // Refresh state
        $this->loadConversation($this->currentConversationId);
        $this->loadConversations();
        $this->dispatch('assistant-message-persisted');
    }

    private function sanitizeAssistantOutput(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $replacements = [
            '/\bchunks?\b/i' => 'bagian dokumen',
            '/\bchunk(?:ing|ed)?\b/i' => 'bagian dokumen',
            '/\bembeddings?\b/i' => 'representasi dokumen',
            '/\bvectors?\b/i' => 'indeks dokumen',
            '/\brag\b/i' => 'konteks dokumen',
            '/\bretrieval\b/i' => 'pencarian dokumen',
            '/\btop\s*[- ]?k\b/i' => 'hasil teratas',
        ];

        $sanitized = $text;
        foreach ($replacements as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, (string) $sanitized);
        }

        return (string) $sanitized;
    }

    public function render()
    {
        return view('livewire.chat.chat-index');
    }
}
