<?php

namespace App\Services\Document\Chunking;

use Illuminate\Support\Facades\Log;

class TextChunker
{
    protected int $chunkSize;
    protected int $chunkOverlap;
    protected TokenCounter $tokenCounter;

    public function __construct(
        ?int $chunkSize = null,
        ?int $chunkOverlap = null
    ) {
        try {
            $this->chunkSize = $chunkSize ?? (function_exists('config') ? config('ai.rag.chunk_size', 1500) : 1500);
            $this->chunkOverlap = $chunkOverlap ?? (function_exists('config') ? config('ai.rag.chunk_overlap', 150) : 150);
        } catch (\Throwable $e) {
            $this->chunkSize = $chunkSize ?? 1500;
            $this->chunkOverlap = $chunkOverlap ?? 150;
        }
        
        $this->tokenCounter = new TokenCounter();
    }

    public function chunk(array $pages): array
    {
        $fullText = $this->mergePages($pages);
        
        if (empty(trim($fullText))) {
            Log::warning('TextChunker: empty content, skipping chunking');
            return [];
        }

        $chunks = $this->splitRecursive($fullText);
        
        $chunkCount = count($chunks);
        if ($chunkCount > 0) {
            $firstChunk = reset($chunks);
            $lastChunk = end($chunks);
            Log::info('TextChunker: created chunks', [
                'count' => $chunkCount,
                'first_tokens' => $this->tokenCounter->count($firstChunk),
                'last_tokens' => $this->tokenCounter->count($lastChunk),
            ]);
        }
        
        return $chunks;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function getChunkOverlap(): int
    {
        return $this->chunkOverlap;
    }

    protected function mergePages(array $pages): string
    {
        $texts = [];
        
        foreach ($pages as $page) {
            $content = $page['page_content'] ?? '';
            $pageNumber = $page['page_number'] ?? 1;
            
            if (!empty(trim($content))) {
                $texts[] = $content;
            }
        }
        
        return implode("\n\n--- Page Break ---\n\n", $texts);
    }

    protected function splitRecursive(string $text): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        
        foreach ($separators as $separator) {
            if ($separator === "") {
                return $this->hardSplit($text);
            }
            
            $segments = explode($separator, $text);
            $result = $this->merge($segments, $separator);
            
            if (count($result) > 1) {
                return $result;
            }
        }
        
        return [$text];
    }

    protected function merge(array $segments, string $separator = ''): array
    {
        if (empty($segments)) {
            return [];
        }

        $chunks = [];
        $currentChunk = "";
        
        foreach ($segments as $i => $segment) {
            if ($segment === "" && $i === count($segments) - 1) {
                continue;
            }
            
            $isLastSegment = ($i === count($segments) - 1);
            
            $segmentWithSep = $isLastSegment ? $segment : $segment . $separator;
            
            $testChunk = $currentChunk === "" 
                ? $segmentWithSep 
                : $currentChunk . $segmentWithSep;
            
            $tokens = $this->tokenCounter->count($testChunk);
            
            if ($tokens > $this->chunkSize && $currentChunk !== "") {
                $chunks[] = $currentChunk;
                $currentChunk = $segment;
                
                if ($this->chunkOverlap > 0 && $i > 0) {
                    $prevSegment = $segments[$i - 1];
                    $overlapTokens = $this->tokenCounter->count($prevSegment . $separator);
                    if ($overlapTokens <= $this->chunkOverlap) {
                        $currentChunk = $prevSegment . $separator . $segment;
                    }
                }
            } else {
                $currentChunk = $testChunk;
            }
        }
        
        if (trim($currentChunk) !== "") {
            $chunks[] = $currentChunk;
        }
        
        return array_values(array_filter($chunks, fn($c) => trim($c) !== ""));
    }

    protected function addOverlap(string $chunk, string $separator = ''): string
    {
        if ($this->chunkOverlap <= 0) {
            return $chunk;
        }

        return $chunk;
    }

    public function addOverlapToEnd(string $chunk, array $nextSegments, string $separator = ''): string
    {
        if ($this->chunkOverlap <= 0 || empty($nextSegments)) {
            return $chunk;
        }

        $overlapTokens = 0;
        $overlapContent = "";
        
        foreach ($nextSegments as $seg) {
            $segWithSep = ($separator !== "") ? $seg . $separator : $seg;
            $segTokens = $this->tokenCounter->count($segWithSep);
            
            if ($overlapTokens + $segTokens <= $this->chunkOverlap) {
                $overlapContent .= $segWithSep;
                $overlapTokens += $segTokens;
            } else {
                break;
            }
        }
        
        return $chunk . trim($overlapContent);
    }

    protected function hardSplit(string $text): array
    {
        $chunks = [];
        $charsPerChunk = $this->chunkSize * TokenCounter::CHARS_PER_TOKEN;
        
        $chunks = str_split($text, $charsPerChunk);
        
        return array_values(array_filter($chunks));
    }
}