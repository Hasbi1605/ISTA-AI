<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Document\LaravelDocumentRetrievalService;
use App\Services\Document\DocumentPolicyService;
use App\Services\LangSearchService;

class LaravelChatService
{
    protected string $model;
    protected bool $webSearchEnabled;
    protected string $webSearchProvider;
    protected ?LaravelDocumentRetrievalService $documentRetrieval;
    protected ?DocumentPolicyService $documentPolicy;
    protected bool $cascadeEnabled;
    protected array $cascadeNodes;
    protected ?LangSearchService $langSearchService;
    protected bool $useLangSearch;

    public function __construct()
    {
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
        $this->webSearchEnabled = config('ai.laravel_ai.web_search.enabled', true);
        $this->webSearchProvider = config('ai.laravel_ai.web_search.provider', 'ddg');
        $this->documentRetrieval = null;
        $this->documentPolicy = null;
        $this->cascadeEnabled = config('ai.cascade.enabled', true);
        $this->cascadeNodes = config('ai.cascade.nodes', []);
        $this->langSearchService = null;
        $this->useLangSearch = config('ai.langsearch.api_key') !== null || config('ai.langsearch.api_key_backup') !== null;
    }

    protected function getLangSearchService(): ?LangSearchService
    {
        if ($this->langSearchService === null && $this->useLangSearch) {
            try {
                $this->langSearchService = app(LangSearchService::class);
            } catch (\Throwable $e) {
                Log::warning('LaravelChatService: LangSearchService not available', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $this->langSearchService;
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
            yield from $this->chatWithDocuments(
                $messages,
                $document_filenames,
                $user_id,
                $force_web_search,
                $source_policy,
                $allow_auto_realtime_web,
                $retrievalService,
                $documentPolicy
            );

            return;
        }

        if ($documentFilenamesValid) {
            yield "⚠️ Chat dengan dokumen aktif belum tersedia via Laravel AI SDK.";
            return;
        }

        $lastMessage = end($messages);
        $prompt = is_array($lastMessage) ? ($lastMessage['content'] ?? '') : (string) $lastMessage;

        $useWebSearch = $this->shouldUseWebSearch($force_web_search, $allow_auto_realtime_web, $source_policy);

        $nodes = $this->cascadeEnabled && !empty($this->cascadeNodes) 
            ? $this->cascadeNodes 
            : [['label' => 'Default', 'provider' => 'openai', 'model' => $this->model, 'api_key' => config('ai.laravel_ai.api_key')]];

        $success = false;
        foreach ($nodes as $index => $node) {
            try {
                $systemPrompt = $this->getSystemPrompt();

                $webSearchResults = [];
                if ($useWebSearch && $this->useLangSearch) {
                    $webSearchResults = $this->performLangSearch($prompt);
                    $systemPrompt = $this->getWebSearchPrompt();
                }

                yield "[MODEL:{$node['label']}]\n";

                $promptToUse = !empty($webSearchResults)
                    ? $this->buildWebSearchContext($webSearchResults) . "\n\n" . $prompt
                    : $prompt;

                yield from $this->streamChatCompletion(
                    $node,
                    $systemPrompt,
                    $promptToUse,
                    $this->getWebSearchSources($webSearchResults)
                );
                $success = true;
                break;
            } catch (\Throwable $e) {
                Log::warning("LaravelChatService: Node [{$node['label']}] failed", [
                    'error' => $e->getMessage(),
                    'is_last' => $index === count($nodes) - 1
                ]);

                if ($this->shouldFallback($e) && $index < count($nodes) - 1) {
                    continue;
                }
                
                if ($index === count($nodes) - 1 && !$success) {
                    yield "\n❌ Maaf, layanan AI sedang tidak tersedia. Silakan coba lagi nanti.";
                }
            }
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

            $prompt = $ragData['prompt'];
            $sources = $ragData['sources'];

            $nodes = $this->cascadeEnabled && !empty($this->cascadeNodes) 
                ? $this->cascadeNodes 
                : [['label' => 'Default', 'provider' => 'openai', 'model' => $this->model, 'api_key' => config('ai.laravel_ai.api_key')]];

            $chatSuccess = false;
            $ragSystemPrompt = 'Anda adalah ISTA AI, asisten kerja internal untuk pegawai Istana Kepresidenan Yogyakarta. '
                . 'Gunakan Bahasa Indonesia yang baku dan luwes. Jawab berdasarkan dokumen yang diberikan.';
            foreach ($nodes as $index => $node) {
                try {
                    yield "[MODEL:{$node['label']}]\n";

                    yield from $this->streamChatCompletion($node, $ragSystemPrompt, $prompt, $sources);
                    $chatSuccess = true;
                    break;
                } catch (\Throwable $e) {
                    Log::warning("LaravelChatService(RAG): Node [{$node['label']}] failed", [
                        'error' => $e->getMessage(),
                        'is_last' => $index === count($nodes) - 1
                    ]);

                    if ($this->shouldFallback($e) && $index < count($nodes) - 1) {
                        continue;
                    }
                    
                    if ($index === count($nodes) - 1 && !$chatSuccess) {
                        yield "\n❌ Maaf, layanan AI sedang tidak tersedia. Silakan coba lagi nanti.";
                    }
                }
            }

            return;
        }

        if ($success && empty($chunks)) {
            if ($policyResult['should_search']) {
                $nodes = $this->cascadeEnabled && !empty($this->cascadeNodes) 
                    ? $this->cascadeNodes 
                    : [['label' => 'Default', 'provider' => 'openai', 'model' => $this->model, 'api_key' => config('ai.laravel_ai.api_key')]];

                $chatSuccess = false;
                foreach ($nodes as $index => $node) {
                    try {
                        $systemPrompt = $this->getSystemPrompt();

                        $webSearchResults = [];
                        if ($this->useLangSearch) {
                            $webSearchResults = $this->performLangSearch($query);
                            $systemPrompt = $this->getWebSearchPrompt();
                        }

                        yield "[MODEL:{$node['label']}]\n";

                        $promptToUse = !empty($webSearchResults)
                            ? $this->buildWebSearchContext($webSearchResults) . "\n\n" . $query
                            : $query;

                        yield from $this->streamChatCompletion(
                            $node,
                            $systemPrompt,
                            $promptToUse,
                            $this->getWebSearchSources($webSearchResults)
                        );
                        $chatSuccess = true;
                        break;
                    } catch (\Throwable $e) {
                        if ($this->shouldFallback($e) && $index < count($nodes) - 1) {
                            continue;
                        }
                        if ($index === count($nodes) - 1 && !$chatSuccess) {
                            yield "\n❌ Maaf, layanan AI sedang tidak tersedia.";
                        }
                    }
                }
                return;
            }

            $noAnswerMessage = $documentPolicy->getNoAnswerPrompt();
            yield $noAnswerMessage;
            return;
        }

        if ($policyResult['should_search']) {
            $nodes = $this->cascadeEnabled && !empty($this->cascadeNodes) 
                ? $this->cascadeNodes 
                : [['label' => 'Default', 'provider' => 'openai', 'model' => $this->model, 'api_key' => config('ai.laravel_ai.api_key')]];

            $chatSuccess = false;
            foreach ($nodes as $index => $node) {
                try {
                    $systemPrompt = $this->getSystemPrompt();

                    $webSearchResults = [];
                    if ($this->useLangSearch) {
                        $webSearchResults = $this->performLangSearch($query);
                        $systemPrompt = $this->getWebSearchPrompt();
                    }

                    yield "[MODEL:{$node['label']}]\n";

                    $promptToUse = !empty($webSearchResults)
                        ? $this->buildWebSearchContext($webSearchResults) . "\n\n" . $query
                        : $query;

                    yield from $this->streamChatCompletion(
                        $node,
                        $systemPrompt,
                        $promptToUse,
                        $this->getWebSearchSources($webSearchResults)
                    );
                    $chatSuccess = true;
                    break;
                } catch (\Throwable $e) {
                    if ($this->shouldFallback($e) && $index < count($nodes) - 1) {
                        continue;
                    }
                    if ($index === count($nodes) - 1 && !$chatSuccess) {
                        yield "\n❌ Maaf, layanan AI sedang tidak tersedia.";
                    }
                }
            }
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
        $configured = config('ai.prompts.system.default');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return <<<'PROMPT'
Anda adalah asisten AI yang helpful dan informative. 
Selalu berikan jawaban yang akurat, jelas, dan relevan.
Jika pengguna bertanya tentang informasi terkini atau memerlukan data realtime, lakukan web search terlebih dahulu.
PROMPT;
    }

    protected function getWebSearchPrompt(): string
    {
        $configured = config('ai.prompts.web_search.assertive_instruction');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return <<<'PROMPT'
Anda adalah asisten AI yang helpful dan informative. 
Selalu berikan jawaban yang akurat, jelas, dan relevan berdasarkan hasil pencarian web terkini.
PROMPT;
    }

    protected function buildWebSearchContext(array $results): string
    {
        if (empty($results)) {
            return '';
        }

        $langSearch = $this->getLangSearchService();
        if (!$langSearch) {
            return '';
        }

        return $langSearch->buildSearchContext($results);
    }

    protected function getWebSearchSources(array $results): array
    {
        $sources = [];
        foreach ($results as $result) {
            $sources[] = [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
            ];
        }
        return $sources;
    }

    /**
     * Stream a chat completion directly via the provider's `/chat/completions` endpoint.
     * Bypasses laravel/ai SDK because some endpoints (e.g. GitHub Models) do not
     * implement the OpenAI Responses API used by the SDK.
     */
    protected function streamChatCompletion(
        array $node,
        string $systemPrompt,
        string $userPrompt,
        array $initialSources = []
    ): \Generator {
        $baseUrl = rtrim($node['base_url'] ?? 'https://api.openai.com/v1', '/');
        $url = $baseUrl . '/chat/completions';

        $body = [
            'model' => $node['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => true,
        ];

        $response = Http::withToken((string) ($node['api_key'] ?? ''))
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true])
            ->timeout((int) config('ai.global.timeout', 120))
            ->post($url, $body);

        if ($response->status() >= 400) {
            throw new \RuntimeException("HTTP request returned status code {$response->status()}");
        }

        $sources = $initialSources;
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(2048);
            if ($chunk === '' || $chunk === false) {
                continue;
            }
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    if ($data === '[DONE]') {
                        break 2;
                    }
                    continue;
                }

                $payload = json_decode($data, true);
                if (!is_array($payload)) {
                    continue;
                }

                $delta = $payload['choices'][0]['delta']['content']
                    ?? $payload['choices'][0]['message']['content']
                    ?? '';
                if ($delta !== '' && $delta !== null) {
                    yield $delta;
                }
            }
        }

        if (!empty($sources)) {
            yield "\n[SOURCES:" . json_encode($sources) . "]\n";
        }
    }

    protected function shouldFallback(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        
        if (str_contains($msg, '413') || str_contains($msg, 'too large') || str_contains($msg, 'context_length_exceeded')) {
            return true;
        }

        if (str_contains($msg, '429') || str_contains($msg, 'rate limit') || str_contains($msg, 'quota')) {
            return true;
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'connection')) {
            return true;
        }

        return true;
    }

    public function performLangSearch(string $query, string $freshness = 'oneWeek', int $count = 5): array
    {
        $langSearch = $this->getLangSearchService();
        
        if (!$langSearch) {
            return [];
        }
        
        $results = $langSearch->search($query, $freshness, $count);
        
        if (count($results) >= 2) {
            $reranked = $langSearch->rerank($query, $results, $count);
            if ($reranked !== null) {
                $urlMap = [];
                foreach ($results as $r) {
                    $urlMap[$r['url']] = $r;
                }
                
                $rerankedResults = [];
                foreach ($reranked as $item) {
                    $doc = $item['document'] ?? null;
                    if ($doc && isset($urlMap[$doc['url'] ?? ''])) {
                        $rerankedResults[] = $urlMap[$doc['url']];
                    }
                }
                
                if (!empty($rerankedResults)) {
                    return $rerankedResults;
                }
            }
        }
        
        return $results;
    }
}
