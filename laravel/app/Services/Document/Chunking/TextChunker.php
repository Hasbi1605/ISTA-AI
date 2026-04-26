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

    protected function merge(array $segments, string $separator = ""): array
    {
        $chunks = [];
        $currentChunk = "";
        
        foreach ($segments as $index => $segment) {
            $testChunk = $currentChunk === "" 
                ? $segment 
                : $currentChunk . ($separator !== "" ? $separator : "") . $segment;
            
            $tokens = $this->tokenCounter->count($testChunk);
            
            if ($tokens > $this->chunkSize && $currentChunk !== "") {
                $chunks[] = $currentChunk;
                
                $overlapText = "";
                if ($this->chunkOverlap > 0) {
                    $currentTokens = $this->tokenCounter->count($currentChunk);
                    if ($currentTokens <= $this->chunkOverlap) {
                        $overlapText = $currentChunk;
                    } else {
                        $overlapText = $this->getTailText($currentChunk, $this->chunkOverlap);
                    }
                }
                
                $currentChunk = $overlapText . ($separator !== "" ? $separator : "") . $segment;
            } else {
                $currentChunk = $testChunk;
            }
        }
        
        if ($currentChunk !== "") {
            $chunks[] = $currentChunk;
        }
        
        return array_values(array_filter($chunks));
    }

    protected function hardSplit(string $text): array
    {
        $chunks = [];
        $charsPerChunk = $this->chunkSize * TokenCounter::CHARS_PER_TOKEN;
        
        $chunks = str_split($text, $charsPerChunk);
        
        return array_values(array_filter($chunks));
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