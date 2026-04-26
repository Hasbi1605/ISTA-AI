<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\EmbeddingCascadeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AiManager;

class HybridRetrievalService
{
    protected EmbeddingCascadeService $embeddingCascade;
    protected ?HydeQueryExpansionService $hydeService;
    protected string $embeddingModel;
    protected int $embeddingDimensions;
    protected int $topK;
    protected bool $hybridEnabled;
    protected float $bm25Weight;
    protected int $rrfK;
    protected bool $pdrEnabled;
    protected int $pdrChildSize;
    protected int $pdrChildOverlap;
    protected int $pdrParentSize;
    protected int $pdrParentOverlap;
    protected bool $hydeEnabled;
    protected string $hydeMode;

    public function __construct()
    {
        $this->embeddingCascade = app(EmbeddingCascadeService::class);
        $this->hydeService = null;
        
        $ragConfig = config('ai.rag', []);
        $this->topK = $ragConfig['top_k'] ?? 5;
        $this->embeddingModel = $ragConfig['embedding_model'] ?? 'text-embedding-3-small';
        $this->embeddingDimensions = (int) ($ragConfig['embedding_dimensions'] ?? 1536);
        
        $hybrid = $ragConfig['hybrid'] ?? [];
        $this->hybridEnabled = $hybrid['enabled'] ?? true;
        $this->bm25Weight = (float) ($hybrid['bm25_weight'] ?? 0.3);
        $this->rrfK = (int) ($hybrid['rrf_k'] ?? 60);
        
        $pdr = $ragConfig['pdr'] ?? [];
        $this->pdrEnabled = $pdr['enabled'] ?? true;
        $this->pdrChildSize = (int) ($pdr['child_chunk_size'] ?? 256);
        $this->pdrChildOverlap = (int) ($pdr['child_chunk_overlap'] ?? 32);
        $this->pdrParentSize = (int) ($pdr['parent_chunk_size'] ?? 1500);
        $this->pdrParentOverlap = (int) ($pdr['parent_chunk_overlap'] ?? 200);

        $hyde = $ragConfig['hyde'] ?? [];
        $this->hydeEnabled = $hyde['enabled'] ?? true;
        $this->hydeMode = $hyde['mode'] ?? 'smart';

        if ($this->hydeEnabled) {
            try {
                $this->hydeService = app(HydeQueryExpansionService::class);
            } catch (\Throwable $e) {
                Log::warning('HybridRetrievalService: HyDE service not available', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function search(string $query, array $filenames, int $topK, string $userId): array
    {
        $searchQuery = $this->getEnhancedQuery($query);

        if ($this->hybridEnabled) {
            return $this->performHybridSearch($searchQuery, $query, $filenames, $topK, $userId);
        }
        
        return $this->performVectorSearchOnly($searchQuery, $query, $filenames, $topK, $userId);
    }

    protected function getEnhancedQuery(string $query): string
    {
        if ($this->hydeService === null || !$this->hydeEnabled) {
            return $query;
        }

        if ($this->hydeMode === 'always') {
            return $this->hydeService->generateEnhancedQuery($query);
        }

        if ($this->hydeMode === 'smart') {
            list($shouldUse, $reason) = $this->hydeService->shouldUseHyde($query);
            if ($shouldUse) {
                Log::info('HyDE: mode=smart — AKTIF (' . $reason . ')');
                return $this->hydeService->generateEnhancedQuery($query);
            }
            Log::debug('HyDE: mode=smart — skip (' . $reason . ')');
        }

        return $query;
    }

    protected function performVectorSearchOnly(string $searchQuery, string $originalQuery, array $filenames, int $topK, string $userId): array
    {
        $documents = $this->getDocumentsForUser($filenames, $userId);
        
        if (empty($documents)) {
            return ['chunks' => [], 'success' => false, 'reason' => 'no_documents'];
        }

        $queryEmbedding = $this->getQueryEmbedding($searchQuery);
        $allChunks = [];

        foreach ($documents as $docData) {
            $document = Document::find($docData['id']);
            if (!$document) continue;

            $this->ensureDocumentIngested($document, false);

            $chunks = $this->getVectorScoredChunks(
                $document, 
                $queryEmbedding,
                $searchQuery,
                $topK * 2
            );

            $allChunks = array_merge($allChunks, $chunks);
        }

        usort($allChunks, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        
        return [
            'chunks' => array_slice($allChunks, 0, $topK),
            'success' => !empty($allChunks),
        ];
    }

    protected function performHybridSearch(string $searchQuery, string $originalQuery, array $filenames, int $topK, string $userId): array
    {
        $documents = $this->getDocumentsForUser($filenames, $userId);
        
        if (empty($documents)) {
            return ['chunks' => [], 'success' => false, 'reason' => 'no_documents'];
        }

        $queryEmbedding = $this->getQueryEmbedding($searchQuery);
        $bm25Results = [];
        $vectorResults = [];

        foreach ($documents as $docData) {
            $document = Document::find($docData['id']);
            if (!$document) continue;

            $this->ensureDocumentIngested($document, $this->pdrEnabled);

            $bm25Scored = $this->getBm25ScoredChunks($document, $originalQuery, $topK * 3);
            $bm25Results = array_merge($bm25Results, $bm25Scored);

            $vectorScored = $this->getVectorScoredChunks($document, $queryEmbedding, $searchQuery, $topK * 3);
            $vectorResults = array_merge($vectorResults, $vectorScored);
        }

        $merged = $this->performRrfMerge($bm25Results, $vectorResults, $topK);

        if ($this->pdrEnabled && !empty($merged)) {
            $merged = $this->resolvePdrParents($merged, $userId);
        }

        return [
            'chunks' => $merged,
            'success' => !empty($merged),
        ];
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

    protected function getQueryEmbedding(string $query): ?array
    {
        try {
            $response = $this->embeddingCascade->embed([$query], $this->embeddingModel);
            return $response->embeddings[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('HybridRetrievalService: query embedding failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function ensureDocumentIngested(Document $document, bool $usePdr): void
    {
        $existingChildChunks = $document->chunks()
            ->where('chunk_type', 'child')
            ->get();

        $hasNonPdrChunks = $existingChildChunks->isNotEmpty() && $existingChildChunks->every(fn($c) => $c->parent_id === null);
        $hasPdrChunks = $existingChildChunks->isNotEmpty() && $existingChildChunks->some(fn($c) => $c->parent_id !== null);

        if ($usePdr && !$hasPdrChunks && $hasNonPdrChunks) {
            Log::info('HybridRetrievalService: upgrading existing non-PDR document to PDR', [
                'document_id' => $document->id,
                'existing_chunks' => $existingChildChunks->count(),
            ]);
            $document->chunks()->delete();
            $existingChildChunks = collect();
        }

        if ($existingChildChunks->isNotEmpty()) {
            return;
        }

        $filePath = Storage::disk('local')->path($document->file_path);
        
        if (!file_exists($filePath)) {
            Log::warning('HybridRetrievalService: file not found', ['path' => $filePath]);
            return;
        }

        $text = $this->extractTextFromFile($filePath, $document);
        
        if (empty(trim($text))) {
            Log::warning('HybridRetrievalService: empty document text', ['id' => $document->id]);
            return;
        }

        if ($usePdr) {
            $this->createPdrChunks($document, $text);
        } else {
            $this->createSimpleChunks($document, $text);
        }

        $this->computeEmbeddingsForChunks($document);

        Log::info('HybridRetrievalService: document ingested', [
            'document_id' => $document->id,
            'use_pdr' => $usePdr,
        ]);
    }

    protected function extractTextFromFile(string $filePath, Document $document): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        try {
            if ($extension === 'pdf') {
                return \Smcc\PdfParser\Parser::parseFile($filePath) ?? '';
            } elseif (in_array($extension, ['docx', 'doc'])) {
                return \PhpOffice\PhpWord\IOFactory::load($filePath)->getContent();
            } elseif (in_array($extension, ['xlsx', 'xls', 'csv'])) {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $content = '';
                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    $content .= $sheet->toString() . "\n";
                }
                return $content;
            }
        } catch (\Throwable $e) {
            Log::warning('HybridRetrievalService: file parse failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return file_get_contents($filePath) ?: '';
    }

    protected function createSimpleChunks(Document $document, string $text): void
    {
        $chunks = $this->splitText($text, config('ai.rag.chunk_size', 1000), config('ai.rag.chunk_overlap', 100));
        
        foreach ($chunks as $index => $chunk) {
            $document->chunks()->create([
                'text_content' => $chunk['content'],
                'chunk_type' => 'child',
                'child_index' => $index,
                'page_number' => $chunk['page_number'] ?? null,
            ]);
        }
    }

    protected function createPdrChunks(Document $document, string $text): void
    {
        $parentChunks = $this->splitText(
            $text, 
            $this->pdrParentSize, 
            $this->pdrParentOverlap
        );

        $parentIds = [];
        
        foreach ($parentChunks as $pIndex => $parentChunk) {
            $parent = $document->chunks()->create([
                'text_content' => $parentChunk['content'],
                'chunk_type' => 'parent',
                'parent_index' => $pIndex,
                'page_number' => $parentChunk['page_number'] ?? null,
            ]);
            
            $parentIds[$parent->id] = $parent->id;

            $childChunks = $this->splitText(
                $parentChunk['content'],
                $this->pdrChildSize,
                $this->pdrChildOverlap
            );

            foreach ($childChunks as $cIndex => $childChunk) {
                $document->chunks()->create([
                    'text_content' => $childChunk['content'],
                    'chunk_type' => 'child',
                    'parent_id' => $parent->id,
                    'child_index' => $cIndex,
                    'page_number' => $childChunk['page_number'] ?? null,
                ]);
            }
        }

        Log::info('HybridRetrievalService: PDR chunks created', [
            'document_id' => $document->id,
            'parent_count' => count($parentIds),
        ]);
    }

    protected function splitText(string $text, int $chunkSize, int $chunkOverlap): array
    {
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
                'page_number' => null,
            ];

            $position += $chunkSize - $chunkOverlap;

            if ($position >= count($chars)) {
                break;
            }
        }

        return $chunks;
    }

    protected function computeEmbeddingsForChunks(Document $document): void
    {
        $chunks = $document->chunks()
            ->where(function ($q) {
                $q->whereNull('embedding')
                    ->orWhere('embedding_model', '!=', $this->embeddingModel)
                    ->orWhereNull('embedding_dimensions')
                    ->orWhere('embedding_dimensions', '!=', $this->embeddingDimensions);
            })
            ->get();

        if ($chunks->isEmpty()) {
            return;
        }

        try {
            $texts = $chunks->pluck('text_content')->toArray();
            $batchSize = 20;
            $batches = array_chunk($texts, $batchSize);
            $batchChunks = $chunks->chunk($batchSize);

            foreach ($batches as $index => $batch) {
                $response = $this->embeddingCascade->embed($batch, $this->embeddingModel);
                $currentBatchChunks = $batchChunks->values()[$index];

                foreach ($currentBatchChunks as $j => $chunk) {
                    $embedding = $response->embeddings[$j] ?? null;
                    if ($embedding) {
                        $chunk->update([
                            'embedding' => $embedding,
                            'embedding_model' => $response->meta->model ?? $this->embeddingModel,
                            'embedding_dimensions' => count($embedding),
                        ]);
                    }
                }
            }

            Log::info('HybridRetrievalService: embeddings computed', [
                'document_id' => $document->id,
                'chunks_count' => $chunks->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('HybridRetrievalService: embedding compute failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getBm25ScoredChunks(Document $document, string $query, int $limit): array
    {
        $chunks = $document->chunks()
            ->where('chunk_type', 'child')
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $texts = $chunks->pluck('text_content')->toArray();
        $scores = $this->calculateBm25Scores($query, $texts);

        $maxScore = !empty($scores) ? max($scores) : 1.0;
        if ($maxScore == 0) {
            $maxScore = 1.0;
        }

        $indexed = [];
        foreach ($chunks as $index => $chunk) {
            $indexed[] = [
                'chunk' => $chunk,
                'score' => $scores[$index] ?? 0.0,
                'normalized' => ($scores[$index] ?? 0.0) / $maxScore,
            ];
        }

        usort($indexed, fn($a, $b) => $b['normalized'] <=> $a['normalized']);

        $results = [];
        foreach (array_slice($indexed, 0, $limit) as $item) {
            $chunk = $item['chunk'];
            $results[] = [
                'content' => $chunk->text_content,
                'score' => $item['normalized'],
                'bm25_score' => $item['score'],
                'filename' => $document->original_name,
                'chunk_index' => $chunk->child_index ?? $chunk->id,
                'chunk_id' => $chunk->id,
                'parent_id' => $chunk->parent_id,
                'chunk_type' => $chunk->chunk_type,
            ];
        }

        return $results;
    }

    protected function getVectorScoredChunks(Document $document, ?array $queryEmbedding, string $query, int $limit): array
    {
        if ($queryEmbedding === null) {
            return $this->getLexicalScoredChunks($document, $query, $limit);
        }
        
        $chunksWithEmbeddings = $document->chunks()
            ->where('chunk_type', 'child')
            ->where('embedding_model', $this->embeddingModel)
            ->where('embedding_dimensions', $this->embeddingDimensions)
            ->whereNotNull('embedding')
            ->get();
            
        if ($chunksWithEmbeddings->isEmpty()) {
            return $this->getLexicalScoredChunks($document, $query, $limit);
        }

        $results = [];
        foreach ($chunksWithEmbeddings as $chunk) {
            $score = $this->cosineSimilarity($queryEmbedding, $chunk->embedding);

            $results[] = [
                'content' => $chunk->text_content,
                'score' => $score,
                'vector_score' => $score,
                'filename' => $document->original_name,
                'chunk_index' => $chunk->child_index ?? $chunk->id,
                'chunk_id' => $chunk->id,
                'parent_id' => $chunk->parent_id,
                'chunk_type' => $chunk->chunk_type,
            ];
        }

        usort($results, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($results, 0, $limit);
    }

    protected function getLexicalScoredChunks(Document $document, string $query, int $limit): array
    {
        $chunks = $document->chunks()
            ->where('chunk_type', 'child')
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $texts = $chunks->pluck('text_content')->toArray();
        $scores = $this->calculateBm25Scores($query, $texts);

        $maxScore = !empty($scores) ? max($scores) : 1.0;
        if ($maxScore == 0) {
            $maxScore = 1.0;
        }

        $results = [];
        foreach ($chunks as $index => $chunk) {
            $normalizedScore = ($scores[$index] ?? 0.0) / $maxScore;
            $results[] = [
                'content' => $chunk->text_content,
                'score' => $normalizedScore,
                'vector_score' => 0.0,
                'bm25_score' => $scores[$index] ?? 0.0,
                'filename' => $document->original_name,
                'chunk_index' => $chunk->child_index ?? $chunk->id,
                'chunk_id' => $chunk->id,
                'parent_id' => $chunk->parent_id,
                'chunk_type' => $chunk->chunk_type,
            ];
        }

        usort($results, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($results, 0, $limit);
    }

    protected function calculateBm25Scores(string $query, array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $avgDocLen = array_sum(array_map('strlen', $documents)) / max(count($documents), 1);
        $k1 = 1.5;
        $b = 0.75;

        $queryTerms = $this->tokenize($query);
        $docLengths = array_map('strlen', $documents);
        $docTermFreqs = [];

        foreach ($documents as $doc) {
            $docTermFreqs[] = $this->computeTermFrequencies($doc);
        }

        $scores = [];
        
        for ($i = 0; $i < count($documents); $i++) {
            $score = 0.0;
            $docLen = $docLengths[$i];
            $tf = $docTermFreqs[$i] ?? [];

            foreach ($queryTerms as $term) {
                $termFreq = $tf[$term] ?? 0;
                
                $numerator = $termFreq * ($k1 + 1);
                $denominator = $termFreq + $k1 * (1 - $b + $b * ($docLen / max($avgDocLen, 1)));
                
                $score += $numerator / max($denominator, 1);
            }

            $scores[] = $score;
        }

        return $scores;
    }

    protected function tokenize(string $text): array
    {
        return array_filter(preg_split('/\W+/', strtolower($text)));
    }

    protected function computeTermFrequencies(string $text): array
    {
        $terms = $this->tokenize($text);
        $freqs = array_count_values($terms);
        return $freqs;
    }

    protected function performRrfMerge(array $bm25Results, array $vectorResults, int $topK): array
    {
        if (empty($vectorResults)) {
            return array_slice($bm25Results, 0, $topK);
        }
        
        if (empty($bm25Results)) {
            return array_slice($vectorResults, 0, $topK);
        }

        $rrfScores = [];
        $docPool = [];

        $bm25Ranked = $this->rankDocsByScore($bm25Results, 'bm25_score');
        $vectorRanked = $this->rankDocsByScore($vectorResults, 'vector_score');

        foreach ($vectorRanked as $rank => $item) {
            $key = $this->getDocKey($item);
            $rrfScores[$key] = $rrfScores[$key] ?? 0.0;
            $rrfScores[$key] += (1 - $this->bm25Weight) / ($this->rrfK + $rank + 1);
            $docPool[$key] = $item;
        }

        foreach ($bm25Ranked as $rank => $item) {
            $key = $this->getDocKey($item);
            $rrfScores[$key] = $rrfScores[$key] ?? 0.0;
            $rrfScores[$key] += $this->bm25Weight / ($this->rrfK + $rank + 1);
            
            if (!isset($docPool[$key])) {
                $docPool[$key] = $item;
            }
        }

        $sortedKeys = array_keys($rrfScores);
        usort($sortedKeys, fn($a, $b) => $rrfScores[$b] <=> $rrfScores[$a]);

        $merged = [];
        foreach ($sortedKeys as $key) {
            if (isset($docPool[$key])) {
                $merged[] = $docPool[$key];
            }
            if (count($merged) >= $topK) {
                break;
            }
        }

        return $merged;
    }

    protected function rankDocsByScore(array $docs, string $scoreKey): array
    {
        usort($docs, fn($a, $b) => ($b[$scoreKey] ?? 0) <=> ($a[$scoreKey] ?? 0));
        return $docs;
    }

    protected function getDocKey(array $doc): string
    {
        $chunkId = $doc['chunk_id'] ?? null;
        if ($chunkId) {
            return 'chunk_' . $chunkId;
        }
        $docId = $doc['document_id'] ?? null;
        $chunkIdx = $doc['chunk_index'] ?? 0;
        return $docId ? "doc_{$docId}_idx_{$chunkIdx}" : substr($doc['content'] ?? '', 0, 60);
    }

    protected function resolvePdrParents(array $childChunks, string $userId): array
    {
        if (empty($childChunks)) {
            return $childChunks;
        }

        $parentIds = [];
        foreach ($childChunks as $chunk) {
            $pid = $chunk['parent_id'] ?? null;
            $ctype = $chunk['chunk_type'] ?? 'child';
            
            if ($pid && $ctype == 'child' && !isset($parentIds[$pid])) {
                $parentIds[$pid] = true;
            }
        }

        if (empty($parentIds)) {
            return $childChunks;
        }

        $parentChunks = DocumentChunk::whereIn('parent_id', array_keys($parentIds))
            ->where('chunk_type', 'parent')
            ->whereHas('document', fn($q) => $q->where('user_id', (int) $userId))
            ->get()
            ->keyBy('parent_id');

        if ($parentChunks->isEmpty()) {
            Log::warning('HybridRetrievalService: parent chunks not found', [
                'parent_ids' => array_keys($parentIds),
            ]);
            return $childChunks;
        }

        $resolved = [];
        $seenParentIds = [];

        foreach ($childChunks as $chunk) {
            $pid = $chunk['parent_id'] ?? null;
            $ctype = $chunk['chunk_type'] ?? 'child';

            if ($pid && $ctype == 'child') {
                if (!isset($seenParentIds[$pid])) {
                    $parent = $parentChunks->get($pid);
                    
                    if ($parent) {
                        $resolved[] = [
                            'content' => $parent->text_content,
                            'score' => $chunk['score'] ?? 0,
                            'filename' => $chunk['filename'],
                            'chunk_index' => $parent->parent_index ?? 0,
                            'chunk_id' => $parent->id,
                            'parent_id' => $pid,
                            'chunk_type' => 'parent',
                            'pdr' => true,
                        ];
                        $seenParentIds[$pid] = true;
                    }
                }
            } else {
                $resolved[] = $chunk;
            }
        }

        $originalChildCount = count($childChunks);
        $pdrCount = count(array_filter($resolved, fn($c) => ($c['pdr'] ?? false) === true));
        
        Log::info('HybridRetrievalService: PDR resolved', [
            'original_children' => $originalChildCount,
            'parent_chunks' => $pdrCount,
            'total' => count($resolved),
        ]);

        return $resolved;
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
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
}