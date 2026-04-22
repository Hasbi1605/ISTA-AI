<?php

namespace App\Services\Runtime;

use App\Contracts\AIRuntimeInterface;
use Illuminate\Support\Facades\Log;

class LaravelAIGateway implements AIRuntimeInterface
{
    public function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        Log::info('LaravelAIGateway: chat not implemented - falling back to Python');

        yield "⚠️ Chat via Laravel AI SDK belum tersedia. Menggunakan fallback.";
    }

    public function documentProcess(string $filePath, string $originalName, int $userId): array
    {
        Log::info('LaravelAIGateway: documentProcess not implemented');

        return [
            'status' => 'error',
            'message' => 'Document process via Laravel AI SDK belum tersedia.',
        ];
    }

    public function documentSummarize(string $filename, ?string $user_id = null): array
    {
        Log::info('LaravelAIGateway: documentSummarize not implemented');

        return [
            'status' => 'error',
            'message' => 'Document summarize via Laravel AI SDK belum tersedia.',
        ];
    }

    public function documentDelete(string $filename): bool
    {
        Log::info('LaravelAIGateway: documentDelete not implemented');

        return false;
    }

    public function isReady(): bool
    {
        return false;
    }
}