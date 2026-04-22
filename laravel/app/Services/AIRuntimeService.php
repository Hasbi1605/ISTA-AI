<?php

namespace App\Services;

class AIRuntimeService
{
    public static function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        return AIRuntimeResolver::for('chat')->getRuntime()->chat(
            $messages,
            $document_filenames,
            $user_id,
            $force_web_search,
            $source_policy,
            $allow_auto_realtime_web
        );
    }

    public static function documentProcess(string $filePath, string $originalName, int $userId): array
    {
        return AIRuntimeResolver::for('document_process')->getRuntime()->documentProcess(
            $filePath,
            $originalName,
            $userId
        );
    }

    public static function documentSummarize(string $filename, ?string $user_id = null): array
    {
        return AIRuntimeResolver::for('document_summarize')->getRuntime()->documentSummarize(
            $filename,
            $user_id
        );
    }

    public static function documentDelete(string $filename): bool
    {
        return AIRuntimeResolver::for('document_delete')->getRuntime()->documentDelete($filename);
    }
}