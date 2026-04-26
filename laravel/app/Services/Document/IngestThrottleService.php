<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\Log;

class IngestThrottleService
{
    protected int $batchSize;
    protected int $maxTokensPerBatch;
    protected float $batchDelay;
    protected int $retryAttempts;
    protected float $retryDelayBase;

    public function __construct(
        ?int $batchSize = null,
        ?int $maxTokensPerBatch = null,
        ?float $batchDelay = null,
        ?int $retryAttempts = null,
        ?float $retryDelayBase = null
    ) {
        try {
            $this->batchSize = $batchSize ?? (function_exists('config') ? config('ai.rag.batching.batch_size', 100) : 100);
            $this->maxTokensPerBatch = $maxTokensPerBatch ?? (function_exists('config') ? config('ai.rag.batching.max_tokens_per_batch', 40000) : 40000);
            $this->batchDelay = $batchDelay ?? (function_exists('config') ? config('ai.rag.batching.delay_seconds', 0.8) : 0.8);
            $this->retryAttempts = $retryAttempts ?? (function_exists('config') ? config('ai.rag.batching.retry_attempts', 3) : 3);
            $this->retryDelayBase = $retryDelayBase ?? (function_exists('config') ? config('ai.rag.batching.retry_delay_base', 1.0) : 1.0);
        } catch (\Throwable $e) {
            $this->batchSize = $batchSize ?? 100;
            $this->maxTokensPerBatch = $maxTokensPerBatch ?? 40000;
            $this->batchDelay = $batchDelay ?? 0.8;
            $this->retryAttempts = $retryAttempts ?? 3;
            $this->retryDelayBase = $retryDelayBase ?? 1.0;
        }
    }

    public function createBatches(array $chunks, array $tokens): array
    {
        $batches = [];
        $currentBatch = [];
        $currentBatchTokens = 0;

        for ($i = 0; $i < count($chunks); $i++) {
            $chunk = $chunks[$i];
            $chunkTokens = $tokens[$i] ?? 0;

            $wouldExceedTokens = ($currentBatchTokens + $chunkTokens) > $this->maxTokensPerBatch;
            $wouldExceedCount = count($currentBatch) >= $this->batchSize;

            if (($wouldExceedTokens || $wouldExceedCount) && !empty($currentBatch)) {
                $batches[] = [
                    'chunks' => $currentBatch,
                    'tokens' => $currentBatchTokens,
                ];
                $currentBatch = [$chunk];
                $currentBatchTokens = $chunkTokens;
            } else {
                $currentBatch[] = $chunk;
                $currentBatchTokens += $chunkTokens;
            }
        }

        if (!empty($currentBatch)) {
            $batches[] = [
                'chunks' => $currentBatch,
                'tokens' => $currentBatchTokens,
            ];
        }

        Log::info('IngestThrottleService: created batches', [
            'total_chunks' => count($chunks),
            'total_batches' => count($batches),
        ]);

        return $batches;
    }

    public function isRateLimitError(\Throwable $error): bool
    {
        $errorMsg = strtolower($error->getMessage());
        
        $indicators = ['429', 'rate limit', 'resource_exhausted', 'resource exhausted', 'quota', '503', 'too many requests'];
        
        foreach ($indicators as $indicator) {
            if (str_contains($errorMsg, $indicator)) {
                return true;
            }
        }
        
        return false;
    }

    public function isTokenLimitError(\Throwable $error): bool
    {
        $errorMsg = strtolower($error->getMessage());
        
        $indicators = ['413', 'tokens_limit_reached', 'too large', 'request body too large'];
        
        foreach ($indicators as $indicator) {
            if (str_contains($errorMsg, $indicator)) {
                return true;
            }
        }
        
        return false;
    }

    public function getRetryDelay(int $attempt): float
    {
        return $this->retryDelayBase * pow(2, $attempt - 1);
    }

    public function shouldRetry(\Throwable $error, int $attempt): bool
    {
        if ($attempt >= $this->retryAttempts) {
            return false;
        }
        
        return $this->isRateLimitError($error);
    }

    public function getBatchDelay(): float
    {
        return $this->batchDelay;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function getMaxTokensPerBatch(): int
    {
        return $this->maxTokensPerBatch;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }
}