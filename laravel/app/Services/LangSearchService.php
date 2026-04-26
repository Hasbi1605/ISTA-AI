<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use DateTime;

class LangSearchService
{
    protected array $apiKeys = [];
    protected string $apiUrl;
    protected string $rerankUrl;
    protected string $rerankModel;
    protected int $timeout;
    protected int $rerankTimeout;
    protected int $cacheTtl;

    public function __construct()
    {
        $this->apiUrl = config('ai.langsearch.api_url', 'https://api.langsearch.com/v1/web-search');
        $this->rerankUrl = config('ai.langsearch.rerank_url', 'https://api.langsearch.com/v1/rerank');
        $this->rerankModel = config('ai.langsearch.rerank_model', 'langsearch-reranker-v1');
        $this->timeout = config('ai.langsearch.timeout', 10);
        $this->rerankTimeout = config('ai.langsearch.rerank_timeout', 8);
        $this->cacheTtl = config('ai.langsearch.cache_ttl', 300);

        $apiKey = config('ai.langsearch.api_key');
        $backupKey = config('ai.langsearch.api_key_backup');

        if ($apiKey) {
            $this->apiKeys[] = $apiKey;
        }
        if ($backupKey) {
            $this->apiKeys[] = $backupKey;
        }
    }

    protected function isConfigured(): bool
    {
        return !empty($this->apiKeys);
    }

    protected function getCacheKey(string $query, string $type = 'search'): string
    {
        $hash = md5($query . $type);
        return "langsearch:{$type}:{$hash}";
    }

    protected function getTimeBucket(): int
    {
        return intval(time() / $this->cacheTtl);
    }

    protected function getCachedResult(string $query, string $type = 'search'): ?array
    {
        $bucket = $this->getTimeBucket();
        $key = $this->getCacheKey("{$query}:{$bucket}", $type);
        
        return Cache::get($key);
    }

    protected function cacheResult(string $query, string $type, array $results): void
    {
        $bucket = $this->getTimeBucket();
        $key = $this->getCacheKey("{$query}:{$bucket}", $type);
        
        Cache::put($key, $results, $this->cacheTtl);
    }

    public function search(string $query, string $freshness = 'oneWeek', int $count = 5): array
    {
        if (!$this->isConfigured()) {
            Log::warning('LangSearch: API key not configured');
            return [];
        }

        $cached = $this->getCachedResult($query);
        if ($cached !== null) {
            Log::info("LangSearch: cache hit for '{$query}'");
            return $cached;
        }

        $payload = [
            'query' => $query,
            'freshness' => $freshness,
            'summary' => true,
            'count' => $count,
        ];

        $data = $this->callWithFallback('search', $payload);
        
        if (!$data) {
            return [];
        }

        $webPages = $data['data']['webPages']['value'] ?? [];
        $results = [];
        
        foreach ($webPages as $item) {
            $results[] = [
                'title' => $item['name'] ?? '',
                'snippet' => $item['snippet'] ?? $item['summary'] ?? '',
                'url' => $item['url'] ?? '',
                'datePublished' => $item['datePublished'] ?? '',
            ];
        }

        Log::info("LangSearch: query='{$query}', results=" . count($results));

        $this->cacheResult($query, 'search', $results);

        return $results;
    }

    public function rerank(string $query, array $documents, ?int $topN = null): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning('LangSearch Rerank: API key not configured');
            return null;
        }

        if (count($documents) < 2) {
            Log::info('LangSearch Rerank: skipping rerank (documents < 2)');
            return null;
        }

        $documents = array_slice($documents, 0, 50);

        $payload = [
            'model' => $this->rerankModel,
            'query' => $query,
            'documents' => $documents,
        ];

        if ($topN !== null) {
            $payload['top_n'] = $topN;
        }

        $data = $this->callWithFallback('rerank', $payload);

        if (!$data) {
            return null;
        }

        $results = $data['results'] ?? [];
        Log::info("LangSearch Rerank: query='{$query}', returned " . count($results) . " results");

        return $results;
    }

    public function buildSearchContext(array $results): string
    {
        if (empty($results)) {
            return '';
        }

        $currentDate = (new DateTime())->format('l, d F Y');
        $template = $this->getWebSearchContextPrompt();

        $resultsFormatted = [];
        foreach ($results as $idx => $result) {
            $title = $result['title'] ?? 'No title';
            $snippet = $result['snippet'] ?? 'No description';
            $url = $result['url'] ?? '';
            $date = $result['datePublished'] ?? '';

            $resultStr = "Hasil " . ($idx + 1) . ":\nJudul: {$title}\nRingkasan: {$snippet}";
            if ($url) {
                $resultStr .= "\nSumber: {$url}";
            }
            if ($date) {
                $resultStr .= "\nTanggal publikasi: {$date}";
            }
            $resultsFormatted[] = $resultStr;
        }

        $resultsStr = implode("\n\n", $resultsFormatted);

        return str_replace(
            ['{current_date}', '{results}'],
            [$currentDate, $resultsStr],
            $template
        );
    }

    protected function getWebSearchContextPrompt(): string
    {
        return <<<'PROMPT'
Hasil pencarian web terkini untuk menjawab pertanyaan Anda.

Tanggal: {current_date}

{results}

Gunakan informasi di atas dari sumber web untuk menjawab pertanyaan pengguna. 
Jangan menyatakan bahwa Anda melakukan web search - langsung gunakan informasinya.
PROMPT;
    }

    protected function callWithFallback(string $type, array $payload): ?array
    {
        $url = $type === 'rerank' ? $this->rerankUrl : $this->apiUrl;
        $timeout = $type === 'rerank' ? $this->rerankTimeout : $this->timeout;

        for ($i = 0; $i < count($this->apiKeys); $i++) {
            $key = $this->apiKeys[$i];
            
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$key}",
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($url, $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if ($type === 'rerank' && ($data['code'] ?? 200) !== 200) {
                        $statusCode = $data['code'] ?? 500;
                        if ($i < count($this->apiKeys) - 1 && in_array($statusCode, [401, 403, 429])) {
                            Log::warning("LangSearch Rerank: API error {$statusCode}. Retrying with backup key...");
                            continue;
                        }
                        Log::error("LangSearch Rerank: API error code={$statusCode}, msg=" . ($data['msg'] ?? ''));
                        return null;
                    }
                    
                    return $data;
                }

                $statusCode = $response->status();
                if ($i < count($this->apiKeys) - 1 && in_array($statusCode, [401, 403, 429]) || $statusCode >= 500) {
                    Log::warning("LangSearch {$type}: attempt " . ($i + 1) . " failed ({$statusCode}). Retrying with backup key...");
                    continue;
                }
                
                Log::error("LangSearch {$type}: API error code={$statusCode}");
                return null;
                
            } catch (\Illuminate\Http\PendingRequestException $e) {
                if ($i < count($this->apiKeys) - 1) {
                    Log::warning("LangSearch {$type}: attempt " . ($i + 1) . " timeout. Retrying with backup key...");
                    continue;
                }
                Log::error("LangSearch {$type}: query='{$payload['query']}', timeout after {$timeout}s");
                return null;
            } catch (\Throwable $e) {
                if ($i < count($this->apiKeys) - 1) {
                    Log::warning("LangSearch {$type}: attempt " . ($i + 1) . " failed. Retrying with backup key...");
                    continue;
                }
                Log::error("LangSearch {$type}: error=" . $e->getMessage());
                return null;
            }
        }

        return null;
    }
}