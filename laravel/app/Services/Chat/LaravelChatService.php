<?php

namespace App\Services\Chat;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\Citation;
use Illuminate\Support\Facades\Log;
use App\Services\Document\LaravelDocumentRetrievalService;
use App\Services\Document\DocumentPolicyService;

class LaravelChatService
{
    protected string $model;
    protected bool $webSearchEnabled;
    protected string $webSearchProvider;
    protected ?LaravelDocumentRetrievalService $documentRetrieval;
    protected ?DocumentPolicyService $documentPolicy;

    public function __construct()
    {
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
        $this->webSearchEnabled = config('ai.laravel_ai.web_search.enabled', true);
        $this->webSearchProvider = config('ai.laravel_ai.web_search.provider', 'ddg');
        $this->documentRetrieval = null;
        $this->documentPolicy = null;
    }

    protected function getDocumentRetrieval(): ?LaravelDocumentRetrievalService
    {
        if ($this->documentRetrieval === null) {
            if (config('ai.laravel_ai.document_retrieval_enabled', false)) {
                try {
                    $this->documentRetrieval = app(LaravelDocumentRetrievalService::class);
                } catch (\Throwable $e) {
                    Log::warning('LaravelChatService: document retrieval not available', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        return $this->documentRetrieval;
    }

    protected function getDocumentPolicy(): ?DocumentPolicyService
    {
        if ($this->documentPolicy === null) {
            $this->documentPolicy = app(DocumentPolicyService::class);
        }
        return $this->documentPolicy;
    }

    public function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        $documentFilenamesValid = $document_filenames !== null && count($document_filenames) > 0;
        $retrievalService = $this->getDocumentRetrieval();
        $documentPolicy = $this->getDocumentPolicy();

        if ($documentFilenamesValid && $retrievalService && $documentPolicy) {
            return $this->chatWithDocuments(
                $messages,
                $document_filenames,
                $user_id,
                $force_web_search,
                $source_policy,
                $allow_auto_realtime_web,
                $retrievalService,
                $documentPolicy
            );
        }

        if ($documentFilenamesValid) {
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

        $sources = [];

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                yield $event->delta;
            } elseif ($event instanceof Citation) {
                $citation = $event->citation;
                $sources[] = [
                    'title' => $citation->title ?? '',
                    'url' => $citation->url ?? '',
                ];
            }
        }

        if (!empty($sources)) {
            yield "\n[SOURCES:" . json_encode($sources) . "]\n";
        }
    }

    protected function chatWithDocuments(
        array $messages,
        ?array $document_filenames,
        ?string $user_id,
        bool $force_web_search,
        ?string $source_policy,
        bool $allow_auto_realtime_web,
        LaravelDocumentRetrievalService $retrievalService,
        DocumentPolicyService $documentPolicy
    ): \Generator {
        $lastMessage = end($messages);
        $query = is_array($lastMessage) ? ($lastMessage['content'] ?? '') : (string) $lastMessage;

        $explicitWebRequest = $documentPolicy->detectExplicitWebRequest($query);

        $policyResult = $documentPolicy->shouldUseWebSearch(
            $query,
            $force_web_search,
            $explicitWebRequest,
            $allow_auto_realtime_web,
            true
        );

        $topK = config('ai.rag.top_k', 5);
        $retrievalResult = $retrievalService->searchRelevantChunks(
            $query,
            $document_filenames ?? [],
            $topK,
            $user_id ?? ''
        );

        $chunks = $retrievalResult['chunks'] ?? [];
        $success = $retrievalResult['success'] ?? false;

        if ($success && !empty($chunks)) {
            $ragData = $retrievalService->buildRagPrompt($query, $chunks);

            $webContext = '';
            if ($policyResult['should_search']) {
                $webContext = '';
            }

            $prompt = $ragData['prompt'];
            $sources = $ragData['sources'];

            $messagesWithRag = array_merge(
                [['role' => 'system', 'content' => $prompt]],
                $messages
            );

            $provider = app(\Laravel\Ai\AiManager::class)->textProvider();

            $agent = \Laravel\Ai\AnonymousAgent::make(
                instructions: 'Anda adalah asisten AI yang menjawab berdasarkan konteks dokumen. '
                    . 'Jawab seringkas mungkin dan ground jawaban ke dokumen.'
            );

            $promptObj = \Laravel\Ai\Prompts\AgentPrompt::for(
                agent: $agent,
                prompt: $query,
                attachments: [],
                provider: $provider,
                model: $this->model,
            );

            $stream = $provider->stream($promptObj);

            $allSources = $sources;

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    yield $event->delta;
                } elseif ($event instanceof Citation) {
                    $citation = $event->citation;
                    $allSources[] = [
                        'title' => $citation->title ?? '',
                        'url' => $citation->url ?? '',
                    ];
                }
            }

            if (!empty($allSources)) {
                yield "\n[SOURCES:" . json_encode($allSources) . "]\n";
            }

            return;
        }

        if ($success && empty($chunks)) {
            if ($policyResult['should_search']) {
                $provider = app(\Laravel\Ai\AiManager::class)->textProvider();

                $tools = [];
                if ($this->webSearchEnabled) {
                    $webSearch = new \Laravel\Ai\Providers\Tools\WebSearch();
                    $tools[] = $provider->webSearchTool($webSearch);
                }

                $agent = \Laravel\Ai\AnonymousAgent::make(
                    instructions: $this->getSystemPrompt(),
                    tools: $tools,
                );

                $promptObj = \Laravel\Ai\Prompts\AgentPrompt::for(
                    agent: $agent,
                    prompt: $query,
                    attachments: [],
                    provider: $provider,
                    model: $this->model,
                );

                yield from $provider->stream($promptObj);
                return;
            }

            $noAnswerMessage = $documentPolicy->getNoAnswerPrompt();
            yield $noAnswerMessage;
            return;
        }

        if ($policyResult['should_search']) {
            $provider = app(\Laravel\Ai\AiManager::class)->textProvider();

            $tools = [];
            if ($this->webSearchEnabled) {
                $webSearch = new \Laravel\Ai\Providers\Tools\WebSearch();
                $tools[] = $provider->webSearchTool($webSearch);
            }

            $agent = \Laravel\Ai\AnonymousAgent::make(
                instructions: $this->getSystemPrompt(),
                tools: $tools,
            );

            $promptObj = \Laravel\Ai\Prompts\AgentPrompt::for(
                agent: $agent,
                prompt: $query,
                attachments: [],
                provider: $provider,
                model: $this->model,
            );

            yield from $provider->stream($promptObj);
            return;
        }

        $errorMessage = $documentPolicy->getDocumentErrorPrompt();
        yield $errorMessage;
    }

    protected function shouldUseWebSearch(bool $force, bool $auto, ?string $policy): bool
    {
        if ($force) {
            return $this->webSearchEnabled;
        }
        if (!$auto) {
            return false;
        }
        if ($policy === 'hybrid_realtime_auto') {
            return $this->webSearchEnabled;
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