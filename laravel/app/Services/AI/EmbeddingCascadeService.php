<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\EmbeddingsResponse;

class EmbeddingCascadeService
{
    protected AiManager $ai;

    public function __construct()
    {
        $this->ai = app(AiManager::class);
    }

    /**
     * Embed text inputs using cascade fallback.
     *
     * @param array $inputs
     * @return EmbeddingsResponse
     * @throws \Exception
     */
    public function embed(array $inputs): EmbeddingsResponse
    {
        $nodes = config('ai.embedding_cascade.nodes', []);
        $enabled = config('ai.embedding_cascade.enabled', true);

        if (!$enabled || empty($nodes)) {
            // Fallback to default provider
            return $this->ai->textProvider()->embeddings(
                $inputs,
                config('ai.rag.embedding_dimensions', 1536),
                config('ai.rag.embedding_model', 'text-embedding-3-small')
            );
        }

        $errors = [];
        foreach ($nodes as $index => $node) {
            try {
                Log::info("EmbeddingCascade: Attempting node {$index}", [
                    'label' => $node['label'],
                    'model' => $node['model'],
                ]);

                $provider = $this->ai->textProvider($node['provider'], [
                    'api_key' => $node['api_key'],
                    'base_url' => $node['base_url'] ?? null,
                ]);

                $response = $provider->embeddings(
                    $inputs,
                    $node['dimensions'],
                    $node['model']
                );

                Log::info("EmbeddingCascade: Success using node {$index}", [
                    'label' => $node['label'],
                ]);

                return $response;
            } catch (\Throwable $e) {
                $errorMsg = "Node {$index} ({$node['label']}) failed: " . $e->getMessage();
                Log::warning("EmbeddingCascade: {$errorMsg}");
                $errors[] = $errorMsg;
            }
        }

        $allErrors = implode("; ", $errors);
        Log::error("EmbeddingCascade: All nodes failed. Errors: {$allErrors}");
        
        throw new \Exception("Embedding cascade failed for all nodes. Errors: {$allErrors}");
    }
}
