<?php

namespace App\Services\Runtime;

use App\Contracts\AIRuntimeInterface;
use App\Services\Chat\LaravelChatService;
use App\Services\Document\LaravelDocumentService;
use App\Services\Document\LaravelDocumentRetrievalService;
use Illuminate\Support\Facades\Log;

class LaravelAIGateway implements AIRuntimeInterface
{
    protected ?LaravelDocumentRetrievalService $documentRetrievalService = null;

    public function chat(
        array $messages,
        ?array $document_filenames = null,
        ?string $user_id = null,
        bool $force_web_search = false,
        ?string $source_policy = null,
        bool $allow_auto_realtime_web = true
    ): \Generator {
        $documentFilenamesValid = $document_filenames !== null && count($document_filenames) > 0;

        if ($documentFilenamesValid && $this->isDocumentRetrievalEnabled()) {
            $retrievalService = $this->getDocumentRetrievalService();

            if ($retrievalService !== null) {
                $service = new LaravelChatService();
                yield from $service->chat(
                    $messages,
                    $document_filenames,
                    $user_id,
                    $force_web_search,
                    $source_policy,
                    $allow_auto_realtime_web
                );
                return;
            }
        }

        if ($documentFilenamesValid) {
            Log::warning('LaravelAIGateway: Document retrieval requested but service not available', [
                'document_filenames' => $document_filenames,
            ]);

            $service = app(LaravelChatService::class);
            yield from $service->chat(
                $messages,
                null,
                $user_id,
                $force_web_search,
                $source_policy,
                $allow_auto_realtime_web
            );
            return;
        }

        $service = app(LaravelChatService::class);

        yield from $service->chat(
            $messages,
            $document_filenames,
            $user_id,
            $force_web_search,
            $source_policy,
            $allow_auto_realtime_web
        );
    }

    public function documentProcess(string $filePath, string $originalName, int $userId): array
    {
        return app(LaravelDocumentService::class)->processDocument($filePath, $originalName, $userId);
    }

    public function documentSummarize(string $filename, ?string $user_id = null): array
    {
        return app(LaravelDocumentService::class)->summarizeDocument($filename, $user_id);
    }

    public function documentDelete(string $filename, ?string $userId = null): bool
    {
        return app(LaravelDocumentService::class)->deleteDocument($filename, $userId);
    }

    public function isReady(): bool
    {
        $apiKey = config('ai.laravel_ai.api_key');

        if (!$apiKey) {
            return false;
        }

        return config('ai.laravel_ai.document_process_enabled', false)
            || config('ai.laravel_ai.document_summarize_enabled', false)
            || config('ai.laravel_ai.document_delete_enabled', true)
            || $this->isDocumentRetrievalEnabled();
    }

    protected function isDocumentRetrievalEnabled(): bool
    {
        return config('ai.laravel_ai.document_retrieval_enabled', false) === true;
    }

    protected function getDocumentRetrievalService(): ?LaravelDocumentRetrievalService
    {
        if ($this->documentRetrievalService === null) {
            if ($this->isDocumentRetrievalEnabled()) {
                try {
                    $this->documentRetrievalService = app(LaravelDocumentRetrievalService::class);
                } catch (\Throwable $e) {
                    Log::warning('LaravelAIGateway: document retrieval service initialization failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        return $this->documentRetrievalService;
    }
}