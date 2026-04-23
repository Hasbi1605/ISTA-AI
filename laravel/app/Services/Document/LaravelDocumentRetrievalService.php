<?php

namespace App\Services\Document;

use App\Contracts\DocumentRetrievalInterface;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Prompts\AgentPrompt;

class LaravelDocumentRetrievalService implements DocumentRetrievalInterface
{
    protected AiManager $ai;
    protected string $model;
    protected int $topK;
    protected bool $useProviderFileSearch;

    private const EXPLICIT_WEB_PATTERNS = [
        '/\bcari\s+di\s+web\b/i',
        '/\bweb\s+search\b/i',
        '/\bbrowse\s+web\b/i',
        '/\bsearch\s+online\b/i',
        '/\bpakai\s+(internet|web)\b/i',
        '/\btolong\s+cari\s+di\s+internet\b/i',
    ];

    private const REALTIME_HIGH_PATTERNS = [
        '/\bsekarang\b/i',
        '/\bhari\s+ini\b/i',
        '/\bterbaru\b/i',
        '/\bterkini\b/i',
        '/\bupdate\b/i',
    ];

    private const REALTIME_MEDIUM_KEYWORDS = [
        'update', 'terbaru', 'terkini', 'berita', 'cuaca', 'jadwal',
    ];

    public function __construct()
    {
        $this->ai = app(AiManager::class);
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
        $this->topK = config('ai.rag.top_k', 5);
        $this->useProviderFileSearch = config('ai.rag.use_provider_file_search', false);
    }

    public function searchRelevantChunks(
        string $query,
        array $filenames,
        int $topK,
        string $userId
    ): array {
        $chunks = [];
        $success = false;

        try {
            if ($this->useProviderFileSearch) {
                $result = $this->searchViaProviderFileSearch($query, $filenames, $topK, $userId);
                $chunks = $result['chunks'] ?? [];
                $success = $result['success'] ?? false;
            } else {
                $documents = $this->getDocumentsForUser($filenames, $userId);

                if (empty($documents)) {
                    Log::info('LaravelDocumentRetrieval: no documents found', [
                        'filenames' => $filenames,
                        'user_id' => $userId,
                    ]);
                    return [
                        'chunks' => [],
                        'success' => false,
                        'reason' => 'no_documents',
                    ];
                }

                $chunks = $this->performSemanticSearch($query, $documents, $topK, $userId);
                $success = !empty($chunks);
            }

            Log::info('LaravelDocumentRetrieval: search completed', [
                'query' => $query,
                'filenames' => $filenames,
                'chunks_found' => count($chunks),
                'success' => $success,
            ]);
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentRetrieval: search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [
                'chunks' => [],
                'success' => false,
                'reason' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        return [
            'chunks' => $chunks,
            'success' => $success,
        ];
    }

    public function buildRagPrompt(
        string $question,
        array $chunks,
        bool $includeSources = true,
        string $webContext = ''
    ): array {
        if (empty($chunks)) {
            return [
                'prompt' => $question,
                'sources' => [],
            ];
        }

        $contextParts = [];
        $sources = [];

        foreach ($chunks as $chunk) {
            $filename = $chunk['filename'] ?? 'Dokumen Tidak Diketahui';
            $contextParts[] = "--- Referensi dari Dokumen: {$filename} ---";
            $contextParts[] = $chunk['content'] ?? '';
            $contextParts[] = '';

            if ($includeSources) {
                $sources[] = [
                    'filename' => $filename,
                    'chunk_index' => $chunk['chunk_index'] ?? 0,
                    'relevance_score' => $chunk['score'] ?? 0,
                ];
            }
        }

        $contextStr = implode("\n", $contextParts);

        $webSection = '';
        if (trim($webContext) !== '') {
            $webSection = "\n\nKONTEKS WEB TERBARU:\n{$webContext}\n";
        }

        $promptTemplate = $this->getRagPromptTemplate();
        $prompt = str_replace(
            ['{context_str}', '{web_section}', '{question}'],
            [$contextStr, $webSection, $question],
            $promptTemplate
        );

        return [
            'prompt' => $prompt,
            'sources' => $sources,
        ];
    }

    public function shouldUseWebSearch(
        string $query,
        bool $forceWebSearch = false,
        bool $explicitWebRequest = false,
        bool $allowAutoRealtimeWeb = true,
        bool $documentsActive = false
    ): array {
        $realtimeIntent = $this->detectRealtimeIntentLevel($query);
        $explicitDetected = $explicitWebRequest || $this->detectExplicitWebRequest($query);

        if ($forceWebSearch) {
            return [
                'should_search' => true,
                'reason_code' => $documentsActive ? 'DOC_WEB_TOGGLE' : 'WEB_TOGGLE',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($explicitDetected) {
            return [
                'should_search' => true,
                'reason_code' => $documentsActive ? 'DOC_WEB_EXPLICIT' : 'EXPLICIT_WEB',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($documentsActive) {
            return [
                'should_search' => false,
                'reason_code' => 'DOC_NO_WEB',
                'realtime_intent' => $realtimeIntent,
            ];
        }

        if ($allowAutoRealtimeWeb) {
            if ($realtimeIntent === 'high') {
                return [
                    'should_search' => true,
                    'reason_code' => 'REALTIME_AUTO_HIGH',
                    'realtime_intent' => $realtimeIntent,
                ];
            }
            if ($realtimeIntent === 'medium') {
                return [
                    'should_search' => true,
                    'reason_code' => 'REALTIME_AUTO_MEDIUM',
                    'realtime_intent' => $realtimeIntent,
                ];
            }
        }

        return [
            'should_search' => false,
            'reason_code' => 'NO_WEB',
            'realtime_intent' => $realtimeIntent,
        ];
    }

    public function detectExplicitWebRequest(string $query): bool
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return false;
        }

        foreach (self::EXPLICIT_WEB_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public function hasDocumentsForUser(string $userId): bool
    {
        return Document::where('user_id', (int) $userId)
            ->where('status', 'ready')
            ->exists();
    }

    protected function getDocumentsForUser(array $filenames, string $userId): array
    {
        $query = Document::where('user_id', (int) $userId)
            ->where('status', 'ready');

        if (!empty($filenames)) {
            $query->whereIn('original_name', $filenames);
        }

        return $query->get()->toArray();
    }

    protected function performSemanticSearch(
        string $query,
        array $documents,
        int $topK,
        string $userId
    ): array {
        if (empty($documents)) {
            return [];
        }

        $chunks = [];
        $documentsDir = storage_path('app/documents');

        foreach ($documents as $doc) {
            $filePath = storage_path('app/' . $doc['file_path']);

            if (!file_exists($filePath)) {
                Log::warning('LaravelDocumentRetrieval: file not found', [
                    'path' => $filePath,
                ]);
                continue;
            }

            $docChunks = $this->extractChunksFromDocument($filePath, $doc);
            $relevantChunks = $this->findRelevantChunks($query, $docChunks, $topK, $doc);

            $chunks = array_merge($chunks, $relevantChunks);
        }

        usort($chunks, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        return array_slice($chunks, 0, $topK);
    }

    protected function extractChunksFromDocument(string $filePath, array $document): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        try {
            if ($extension === 'pdf') {
                return $this->extractPdfChunks($filePath, $document);
            } elseif (in_array($extension, ['docx', 'doc'])) {
                return $this->extractDocxChunks($filePath, $document);
            } elseif (in_array($extension, ['xlsx', 'xls', 'csv'])) {
                return $this->extractExcelChunks($filePath, $document);
            }
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentRetrieval: extract chunks failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            [
                'content' => file_get_contents($filePath) ?: '',
                'chunk_index' => 0,
                'filename' => $document['original_name'] ?? basename($filePath),
            ],
        ];
    }

    protected function extractPdfChunks(string $filePath, array $document): array
    {
        try {
            $content = \Smcc\PdfParser\Parser::parseFile($filePath);
            return $this->createChunksFromText($content, $document);
        } catch (\Throwable $e) {
            Log::warning('LaravelDocumentRetrieval: PDF parse failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function extractDocxChunks(string $filePath, array $document): array
    {
        try {
            $content = \PhpOffice\PhpWord\IOFactory::load($filePath)->getContent();
            return $this->createChunksFromText($content, $document);
        } catch (\Throwable $e) {
            Log::warning('LaravelDocumentRetrieval: DOCX parse failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function extractExcelChunks(string $filePath, array $document): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $content = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $content .= $sheet->toString() . "\n";
            }
            return $this->createChunksFromText($content, $document);
        } catch (\Throwable $e) {
            Log::warning('LaravelDocumentRetrieval: Excel parse failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function createChunksFromText(string $text, array $document): array
    {
        $chunkSize = config('ai.rag.chunk_size', 1000);
        $chunkOverlap = config('ai.rag.chunk_overlap', 100);

        $text = preg_replace('/\s+/', ' ', trim($text));
        $chars = mb_str_split($text);

        $chunks = [];
        $position = 0;

        while ($position < count($chars)) {
            $end = min($position + $chunkSize, count($chars));
            $chunkText = implode('', array_slice($chars, $position, $end - $position));

            if (trim($chunkText) === '') {
                break;
            }

            $chunks[] = [
                'content' => $chunkText,
                'chunk_index' => count($chunks),
                'filename' => $document['original_name'] ?? 'unknown',
                'document_id' => $document['id'] ?? null,
            ];

            $position += $chunkSize - $chunkOverlap;

            if ($position >= count($chars)) {
                break;
            }
        }

        return $chunks;
    }

    protected function findRelevantChunks(
        string $query,
        array $docChunks,
        int $topK,
        array $document
    ): array {
        if (empty($docChunks)) {
            return [];
        }

        $provider = $this->ai->textProvider();

        $relevantChunks = [];
        foreach ($docChunks as $chunk) {
            $score = $this->calculateSimilarity($query, $chunk['content'], $provider);

            $relevantChunks[] = array_merge($chunk, [
                'score' => $score,
            ]);
        }

        usort($relevantChunks, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        return array_slice($relevantChunks, 0, $topK);
    }

    protected function calculateSimilarity(string $query, string $content, $provider): float
    {
        try {
            $model = config('ai.rag.embedding_model', 'text-embedding-3-small');

            $embeddingResponse = $provider->embeddings(
                new \Laravel\Ai\Embeddings\EmbeddingPrompt(
                    model: $model,
                    input: $query . ' ' . substr($content, 0, 500),
                )
            );

            $queryEmbedding = $embeddingResponse->embeddings[0] ?? [0];
            $contentEmbedding = $embeddingResponse->embeddings[1] ?? [0];

            return $this->cosineSimilarity($queryEmbedding, $contentEmbedding);
        } catch (\Throwable $e) {
            Log::debug('LaravelDocumentRetrieval: similarity calculation failed', [
                'error' => $e->getMessage(),
            ]);

            $queryTerms = array_unique(preg_split('/\s+/', strtolower($query)));
            $contentTerms = array_unique(preg_split('/\s+/', strtolower($content)));

            $intersection = array_intersect($queryTerms, $contentTerms);
            $union = array_unique(array_merge($queryTerms, $contentTerms));

            if (count($union) === 0) {
                return 0.0;
            }

            return count($intersection) / count($union);
        }
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        $minLen = min(count($a), count($b));
        for ($i = 0; $i < $minLen; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA === 0 || $normB === 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    protected function searchViaProviderFileSearch(
        string $query,
        array $filenames,
        int $topK,
        string $userId
    ): array {
        try {
            $documents = $this->getDocumentsForUser($filenames, $userId);

            if (empty($documents)) {
                return ['chunks' => [], 'success' => false];
            }

            $aiDocuments = [];
            foreach ($documents as $doc) {
                $filePath = storage_path('app/' . $doc['file_path']);
                if (file_exists($filePath)) {
                    $aiDocuments[] = AiDocument::fromPath($filePath);
                }
            }

            if (empty($aiDocuments)) {
                return ['chunks' => [], 'success' => false];
            }

            $agent = AnonymousAgent::make(
                instructions: 'Anda adalah asisten AI yang menjawab berdasarkan dokumen yang diberikan. '
                    . 'Jawab seringkas mungkin dan gunakan konteks dari dokumen.'
            );

            $files = new Files(...$aiDocuments);

            $prompt = new AgentPrompt(
                agent: $agent,
                prompt: "Berdasarkan pertanyaan berikut, carikan informasi yang relevan dari dokumen:\n\n{$query}",
                files: $files,
                provider: $this->ai->textProvider(),
                model: $this->model,
            );

            $result = $this->ai->textProvider()->prompt($prompt);

            $chunks = [
                [
                    'content' => $result->text ?? '',
                    'score' => 1.0,
                    'filename' => $filenames[0] ?? 'document',
                    'chunk_index' => 0,
                ],
            ];

            return ['chunks' => $chunks, 'success' => true];
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentRetrieval: provider file search failed', [
                'error' => $e->getMessage(),
            ]);
            return ['chunks' => [], 'success' => false];
        }
    }

    protected function detectRealtimeIntentLevel(string $query): string
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return 'low';
        }

        foreach (self::REALTIME_HIGH_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return 'high';
            }
        }

        $hits = 0;
        foreach (self::REALTIME_MEDIUM_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                $hits++;
            }
        }

        if ($hits >= 2) {
            return 'medium';
        }
        if ($hits === 1 && str_word_count($normalized) <= 4) {
            return 'medium';
        }

        return 'low';
    }

    protected function getRagPromptTemplate(): string
    {
        return config(
            'ai.prompts.rag',
            <<<'PROMPT'
Anda adalah asisten AI yang menjawab berdasarkan dokumen yang diberikan.

Jika menjawab berdasarkan dokumen, gunakan informasi dari konteks di bawah ini. 
Jangan membuat informasi yang tidak ada di dokumen.

KONTEKS DOKUMEN:
{context_str}
{web_section}

Pertanyaan: {question}

JAWABAN:
PROMPT
        );
    }
}