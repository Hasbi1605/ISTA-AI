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
        $fullText = $this->mergePages($pages);
        
        if (empty(trim($fullText))) {
            Log::warning('PdrChunker: empty content, skipping chunking');
            return [];
        }
        
        $parentChunks = $this->createParentChunks($fullText);
        
        $chunks = [];
        
        foreach ($parentChunks as $pIndex => $parentText) {
            $parentId = $this->generateParentId($filename, $userId, $pIndex, $parentText);
            
            $chunks[] = [
                'text' => $parentText,
                'chunk_type' => 'parent',
                'parent_id' => $parentId,
                'parent_index' => $pIndex,
                'page_number' => 1,
                'metadata' => [
                    'filename' => $filename,
                    'user_id' => $userId,
                    'chunk_type' => 'parent',
                    'parent_id' => $parentId,
                    'parent_index' => $pIndex,
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
                    'page_number' => 1,
                    'metadata' => [
                        'filename' => $filename,
                        'user_id' => $userId,
                        'chunk_type' => 'child',
                        'parent_id' => $parentId,
                        'parent_index' => $pIndex,
                        'child_index' => $cIndex,
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

    protected function mergePages(array $pages): string
    {
        $texts = [];
        
        foreach ($pages as $page) {
            $content = $page['page_content'] ?? '';
            if (!empty(trim($content))) {
                $texts[] = $content;
            }
        }
        
        return implode("\n\n", $texts);
    }

    protected function createParentChunks(string $text): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        
        foreach ($separators as $separator) {
            if ($separator === "") {
                break;
            }
            
            $segments = explode($separator, $text);
            $chunks = $this->mergeByTokens($segments, $this->parentChunkSize, $this->parentChunkOverlap, $separator);
            
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
            $chunks = $this->mergeByTokens($segments, $this->childChunkSize, $this->childChunkOverlap, $separator);
            
            if (count($chunks) > 1) {
                return $chunks;
            }
        }
        
        $charsPerChunk = $this->childChunkSize * TokenCounter::CHARS_PER_TOKEN;
        
        return str_split($parentText, $charsPerChunk);
    }

    protected function mergeByTokens(array $segments, int $targetSize, int $overlap, string $separator = ""): array
    {
        $chunks = [];
        $currentChunk = "";
        
foreach ($segments as $index => $segment) {
            $testChunk = $currentChunk === "" 
                ? $segment 
                : $currentChunk . ($separator !== "" ? $separator : "") . $segment;
            
            $tokens = $this->tokenCounter->count($testChunk);
            
            if ($tokens > $targetSize && $currentChunk !== "") {
                $chunks[] = trim($currentChunk);
                
                $overlapText = "";
                if ($overlap > 0) {
                    $overlapText = $this->getTailText($currentChunk, $overlap);
                }
                
                $currentChunk = $overlapText . ($separator !== "" ? $separator : "") . $segment;
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

    protected function getTailText(string $text, int $maxTokens): string
    {
        $textTokens = $this->tokenCounter->count($text);
        
        if ($textTokens <= $maxTokens) {
            return $text;
        }
        
        $charsPerToken = TokenCounter::CHARS_PER_TOKEN;
        $targetChars = $maxTokens * $charsPerToken;
        
        if ($targetChars >= strlen($text)) {
            return $text;
        }
        
        $tail = substr($text, -$targetChars);
        
        $lastSpace = strrpos($tail, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $tail = substr($tail, $lastSpace + 1);
        }
        
        return ltrim($tail) ?: $text;
    }
}