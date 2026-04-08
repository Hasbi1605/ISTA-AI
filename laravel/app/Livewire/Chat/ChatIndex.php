<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Document;
use App\Services\AIService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ChatIndex extends Component
{
    public $prompt = '';
    public $currentConversationId;
    public $messages = [];
    public $conversations = [];
    public $selectedDocuments = [];
    public $availableDocuments = [];
    public $showDocumentSelector = false;
    public $sources = [];
    public $showOlderChats = false;
    
    // Maximum chats to show before "Show More"
    const MAX_VISIBLE_CHATS = 10;

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
        $this->conversations = Auth::user()->conversations()->orderBy('updated_at', 'desc')->get();
    }

    public function loadAvailableDocuments()
    {
        $this->availableDocuments = Document::where('user_id', Auth::id())
            ->where('status', 'ready')
            ->orderBy('created_at', 'desc')
            ->get();
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
        $this->sources = [];
    }

    public function toggleDocumentSelector()
    {
        $this->showDocumentSelector = !$this->showDocumentSelector;
    }

    public function toggleDocument($documentId)
    {
        if (in_array($documentId, $this->selectedDocuments)) {
            $this->selectedDocuments = array_values(array_filter($this->selectedDocuments, function($id) use ($documentId) {
                return $id != $documentId;
            }));
        } else {
            $this->selectedDocuments[] = $documentId;
        }
    }

    public function selectAllDocuments()
    {
        $this->selectedDocuments = $this->availableDocuments->pluck('id')->toArray();
    }

    public function clearDocumentSelection()
    {
        $this->selectedDocuments = [];
    }

    public function toggleOlderChats()
    {
        $this->showOlderChats = !$this->showOlderChats;
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

    public function sendMessage(AIService $aiService)
    {
        // Mencegah PHP kill process (Time Limit Exceeded) akibat lamanya process LLM
        set_time_limit(120);

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
        $userPrompt = $this->prompt;
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
        if (!empty($this->selectedDocuments)) {
            $documentFilenames = Document::whereIn('id', $this->selectedDocuments)
                ->pluck('original_name')
                ->toArray();
        }
        
        // Fetch stream from AIService
        foreach ($aiService->sendChat($history, $documentFilenames, (string) Auth::id()) as $chunk) {
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
            
            if ($chunk !== '') {
                $fullResponse .= $chunk;
                $this->stream('assistant-output', $chunk);
            }
        }

        // 5. Finalize: Save AI Message to DB (remove sources metadata completely)
        // Clean up any remaining source markers
        $cleanContent = preg_replace('/\[SOURCES:\[.+?\]\]/s', '', $fullResponse);
        $cleanContent = trim($cleanContent);
        
        Message::create([
            'conversation_id' => $this->currentConversationId,
            'role' => 'assistant',
            'content' => $cleanContent
        ]);

        // Refresh state
        $this->loadConversation($this->currentConversationId);
        $this->loadConversations();
    }

    public function render()
    {
        return view('livewire.chat.chat-index');
    }
}
