<?php

namespace App\Contracts;

interface AIRuntimeInterface
{
    public function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator;

    public function documentProcess(string $filePath, string $originalName, int $userId): array;

    public function documentSummarize(string $filename, ?string $user_id = null): array;

    public function documentDelete(string $filename): bool;

    public function isReady(): bool;
}