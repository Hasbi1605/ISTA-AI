<?php

namespace App\Services;

class AIService
{
    public function sendChat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        return AIRuntimeService::chat(
            $messages,
            $document_filenames,
            $user_id,
            $force_web_search,
            $source_policy,
            $allow_auto_realtime_web
        );
    }

    public function summarizeDocument(string $filename, ?string $user_id = null): array
    {
        return AIRuntimeService::documentSummarize($filename, $user_id);
    }
}