<?php

namespace App\Services\Document\Chunking;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PdrChunker
{
    protected int $parentChunkSize;
    protected int $parentChunkOverlap;
    protected int $childChunkSize;
    protected int $childChunkOverlap;
    protected TokenCounter $tokenCounter;

    public function __construct(
        ?int $parentChunkSize = null,
        ?int $parentChunkOverlap = null,
        ?int $childChunkSize = null,
        ?int $childChunkOverlap = null
    ) {
        try {
            $this->parentChunkSize = $parentChunkSize ?? (function_exists('config') ? config('ai.rag.pdr.parent_chunk_size', 1500) : 1500);
            $this->parentChunkOverlap = $parentChunkOverlap ?? (function_exists('config') ? config('ai.rag.pdr.parent_chunk_overlap', 200) : 200);
            $this->childChunkSize = $childChunkSize ?? (function_exists('config') ? config('ai.rag.pdr.child_chunk_size', 256) : 256);
            $this->childChunkOverlap = $childChunkOverlap ?? (function_exists('config') ? config('ai.rag.pdr.child_chunk_overlap', 32) : 32);
        } catch (\Throwable $e) {
            $this->parentChunkSize = $parentChunkSize ?? 1500;
            $this->parentChunkOverlap = $parentChunkOverlap ?? 200;
            $this->childChunkSize = $childChunkSize ?? 256;
            $this->childChunkOverlap = $childChunkOverlap ?? 32;
        }
        
        $this->tokenCounter = new TokenCounter();
    }

    public function chunk(array $pages, string $filename, string $userId): array
    {
        $pagesWithMetadata = $this->mergePages($pages);
        
        if (empty($pagesWithMetadata)) {
            Log::warning('PdrChunker: empty content, skipping chunking');
            return [];
        }

        $fullText = $this->getFullText($pagesWithMetadata);
        
        if (empty(trim($fullText))) {
            Log::warning('PdrChunker: empty content, skipping chunking');
            return [];
        }

        $parentChunks = $this->createParentChunks($fullText);
        
        $chunks = [];
        $totalParentChunks = count($parentChunks);
        
        foreach ($parentChunks as $pIndex => $parentText) {
            $parentId = $this->generateParentId($filename, $userId, $pIndex, $parentText);
            $pageNumber = $this->getPageNumberForChunk($pagesWithMetadata, $pIndex, $totalParentChunks);
            
            $chunks[] = [
                'text' => $parentText,
                'chunk_type' => 'parent',
                'parent_id' => $parentId,
                'parent_index' => $pIndex,
                'page_number' => $pageNumber,
                'metadata' => [
                    'filename' => $filename,
                    'user_id' => $userId,
                    'chunk_type' => 'parent',
                    'parent_id' => $parentId,
                    'parent_index' => $pIndex,
                    'page_number' => $pageNumber,
                ],
            ];
            
            $childChunks = $this->createChildChunks($parentText, $parentId, $pIndex);
            
            foreach ($childChunks as $cIndex => $childText) {
                $chunks[] = [
                    'text' => $childText,
                    'chunk_type' => 'child',
                    'parent_id' => $parentId,
                    'parent_index' => $pIndex,
                    'child_index' => $cIndex,
                    'page_number' => $pageNumber,
                    'metadata' => [
                        'filename' => $filename,
                        'user_id' => $userId,
                        'chunk_type' => 'child',
                        'parent_id' => $parentId,
                        'parent_index' => $pIndex,
                        'child_index' => $cIndex,
                        'page_number' => $pageNumber,
                    ],
                ];
            }
        }
        
        $parentCount = count(array_filter($chunks, fn($c) => $c['chunk_type'] === 'parent'));
        $childCount = count(array_filter($chunks, fn($c) => $c['chunk_type'] === 'child'));
        
        Log::info('PdrChunker: created chunks', [
            'parent_chunks' => $parentCount,
            'child_chunks' => $childCount,
        ]);
        
        return $chunks;
    }

    public function getParentChunkSize(): int
    {
        return $this->parentChunkSize;
    }

    public function getChildChunkSize(): int
    {
        return $this->childChunkSize;
    }

    protected function mergePages(array $pages): array
    {
        $pagesWithMetadata = [];
        
        foreach ($pages as $page) {
            $content = $page['page_content'] ?? '';
            if (!empty(trim($content))) {
                $pagesWithMetadata[] = [
                    'content' => $content,
                    'page_number' => $page['page_number'] ?? 1,
                    'source' => $page['source'] ?? 'unknown',
                ];
            }
        }
        
        return $pagesWithMetadata;
    }

    protected function getFullText(array $pagesWithMetadata): string
    {
        $texts = [];
        
        foreach ($pagesWithMetadata as $page) {
            $texts[] = $page['content'];
        }
        
        return implode("\n\n", $texts);
    }

    protected function getPageNumberForChunk(array $pagesWithMetadata, int $chunkIndex, int $totalChunks): int
    {
        if (empty($pagesWithMetadata)) {
            return 1;
        }

        $totalPages = count($pagesWithMetadata);
        
        if ($totalPages === 1) {
            return $pagesWithMetadata[0]['page_number'];
        }

        $pageIndex = min(floor($chunkIndex * $totalPages / $totalChunks), $totalPages - 1);
        
        return $pagesWithMetadata[$pageIndex]['page_number'] ?? 1;
    }

    protected function createParentChunks(string $text): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        
        foreach ($separators as $separator) {
            if ($separator === "") {
                break;
            }
            
            $segments = explode($separator, $text);
            $chunks = $this->mergeByTokens($segments, $this->parentChunkSize, $this->parentChunkOverlap);
            
            if (count($chunks) > 1) {
                return $chunks;
            }
        }
        
        $charsPerChunk = $this->parentChunkSize * TokenCounter::CHARS_PER_TOKEN;
        
        return str_split($text, $charsPerChunk);
    }

    protected function createChildChunks(string $parentText, string $parentId, int $parentIndex): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        
        foreach ($separators as $separator) {
            if ($separator === "") {
                break;
            }
            
            $segments = explode($separator, $parentText);
            $chunks = $this->mergeByTokens($segments, $this->childChunkSize, $this->childChunkOverlap);
            
            if (count($chunks) > 1) {
                return $chunks;
            }
        }
        
        $charsPerChunk = $this->childChunkSize * TokenCounter::CHARS_PER_TOKEN;
        
        return str_split($parentText, $charsPerChunk);
    }

    protected function mergeByTokens(array $segments, int $targetSize, int $overlap): array
    {
        $chunks = [];
        $currentChunk = "";
        
        foreach ($segments as $index => $segment) {
            $testChunk = $currentChunk === "" ? $segment : $currentChunk . $segment;
            
            $tokens = $this->tokenCounter->count($testChunk);
            
            if ($tokens > $targetSize && $currentChunk !== "") {
                $chunks[] = trim($currentChunk);
                
                $overlapText = "";
                if ($overlap > 0 && $index > 0) {
                    $prevSegment = $segments[$index - 1];
                    $prevTokens = $this->tokenCounter->count($prevSegment);
                    if ($prevTokens <= $overlap) {
                        $overlapText = $prevSegment;
                    } else {
                        $prevChars = substr($prevSegment, -min(100, strlen($prevSegment)));
                        $overlapText = $prevChars;
                    }
                }
                
                $currentChunk = $overlapText . $segment;
            } else {
                $currentChunk = $testChunk;
            }
        }
        
        if (trim($currentChunk) !== "") {
            $chunks[] = trim($currentChunk);
        }
        
        return array_filter($chunks);
    }

    protected function generateParentId(string $filename, string $userId, int $index, string $text): string
    {
        $raw = "{$filename}:{$userId}:{$index}:" . substr($text, 0, 50);
        
        return md5($raw);
    }
}