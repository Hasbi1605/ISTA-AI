<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Message;
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

    public function mount($id = null)
    {
        $this->loadConversations();
        
        if ($id) {
            $this->loadConversation($id);
        }
    }

    public function loadConversations()
    {
        $this->conversations = Auth::user()->conversations()->orderBy('updated_at', 'desc')->get();
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
    }

    public function sendMessage(AIService $aiService)
    {
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

        // Fetch stream from AIService
        foreach ($aiService->sendChat($history) as $chunk) {
            // Parse model indicator from the first chunk
            if (preg_match('/\[MODEL:(.+?)\]\n?/', $chunk, $matches)) {
                $modelName = $matches[1];
                $chunk = preg_replace('/\[MODEL:.+?\]\n?/', '', $chunk);
                $this->stream('model-name', $modelName);
            }
            
            if ($chunk !== '') {
                $fullResponse .= $chunk;
                $this->stream('assistant-output', $chunk);
            }
        }

        // 5. Finalize: Save AI Message to DB
        Message::create([
            'conversation_id' => $this->currentConversationId,
            'role' => 'assistant',
            'content' => $fullResponse
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
