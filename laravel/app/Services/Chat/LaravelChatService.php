<?php

namespace App\Services\Chat;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Illuminate\Support\Facades\Log;

class LaravelChatService
{
    protected string $model;
    protected bool $webSearchEnabled;
    protected string $webSearchProvider;

    public function __construct()
    {
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
        $this->webSearchEnabled = config('ai.laravel_ai.web_search.enabled', true);
        $this->webSearchProvider = config('ai.laravel_ai.web_search.provider', 'ddg');
    }

    public function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        if ($document_filenames !== null && count($document_filenames) > 0) {
            yield "⚠️ Chat dengan dokumen aktif belum tersedia via Laravel AI SDK.";
            return;
        }

        $provider = app(\Laravel\Ai\AiManager::class)->textProvider();

        $lastMessage = end($messages);
        $prompt = is_array($lastMessage) ? ($lastMessage['content'] ?? '') : (string) $lastMessage;

        $useWebSearch = $this->shouldUseWebSearch($force_web_search, $allow_auto_realtime_web, $source_policy);

        $tools = [];
        if ($useWebSearch && $this->webSearchEnabled) {
            $webSearch = new \Laravel\Ai\Providers\Tools\WebSearch();
            $tools[] = $provider->webSearchTool($webSearch);
        }

        $agent = \Laravel\Ai\AnonymousAgent::make(
            instructions: $this->getSystemPrompt(),
            tools: $tools,
        );

        $stream = $provider->stream(
            \Laravel\Ai\Prompts\AgentPrompt::for(
                agent: $agent,
                prompt: $prompt,
                attachments: [],
                provider: $provider,
                model: $this->model,
            )
        );

        foreach ($stream as $event) {
            if (isset($event->text)) {
                yield $event->text;
            } elseif (isset($event->delta) && isset($event->delta->text)) {
                yield $event->delta->text;
            }
        }
    }

    protected function shouldUseWebSearch(bool $force, bool $auto, ?string $policy): bool
    {
        if ($force) {
            return $this->webSearchEnabled;
        }
        if (!$auto) {
            return false;
        }
        if ($policy === 'web-only' || $policy === 'web-preferred') {
            return $this->webSearchEnabled;
        }
        return false;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Anda adalah asisten AI yang helpful dan informative. 
Selalu berikan jawaban yang akurat, jelas, dan relevan.
Jika pengguna bertanya tentang informasi terkini atau memerlukan data realtime, lakukan web search terlebih dahulu.
PROMPT;
    }
}