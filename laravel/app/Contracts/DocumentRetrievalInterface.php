<?php

namespace App\Contracts;

interface DocumentRetrievalInterface
{
    public function searchRelevantChunks(
        string $query,
        array $filenames,
        int $topK,
        string $userId
    ): array;

    public function buildRagPrompt(
        string $question,
        array $chunks,
        bool $includeSources = true,
        string $webContext = ''
    ): array;

    public function shouldUseWebSearch(
        string $query,
        bool $forceWebSearch = false,
        bool $explicitWebRequest = false,
        bool $allowAutoRealtimeWeb = true,
        bool $documentsActive = false
    ): array;

    public function detectExplicitWebRequest(string $query): bool;

    public function hasDocumentsForUser(string $userId): bool;
}