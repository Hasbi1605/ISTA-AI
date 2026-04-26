<?php

namespace App\Services\Document\Chunking;

class TokenCounter
{
    public const CHARS_PER_TOKEN = 4;

    public function count(string $text): int
    {
        if (empty(trim($text))) {
            return 0;
        }

        $chars = mb_strlen($text, 'UTF-8');
        
        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    public function estimate(string $text, int $targetTokens): int
    {
        $currentTokens = $this->count($text);
        
        $ratio = $currentTokens > 0 ? $targetTokens / $currentTokens : 0;
        
        return (int) ($currentTokens * $ratio);
    }
}