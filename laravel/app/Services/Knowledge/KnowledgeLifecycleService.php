<?php

namespace App\Services\Knowledge;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AIUsageEvent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class KnowledgeLifecycleService
{
    public const MAX_DOCUMENT_SIZE_KILOBYTES = 51_200;

    public const MAX_DOCUMENT_SIZE_BYTES = self::MAX_DOCUMENT_SIZE_KILOBYTES * 1024;

    public const STORAGE_DIRECTORY = 'knowledge';

    /**
     * Allowed mime types mirror the per-user document pipeline so the
     * Python ingest pipeline can be re-used without changes.
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    /**
     * Upload a knowledge document and dispatch processing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upload(UploadedFile $file, User $admin, array $attributes = []): KnowledgeDocument
    {
        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();

        $originalName = (string) $file->getClientOriginalName();
        $mimeType = (string) ($file->getMimeType() ?? 'application/octet-stream');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $size = $file->getSize();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
                userId: (int) $admin->id,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $mimeType,
                    'reason' => 'invalid_mime_type',
                    'origin' => 'admin',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'invalid_mime_type',
            );

            throw ValidationException::withMessages([
                'file' => 'Tipe file tidak didukung. Gunakan PDF, DOCX, XLSX, atau CSV.',
            ]);
        }

        if ($size !== null && $size > self::MAX_DOCUMENT_SIZE_BYTES) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
                userId: (int) $admin->id,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $mimeType,
                    'file_size_bytes' => $size,
                    'reason' => 'file_too_large',
                    'origin' => 'admin',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'file_too_large',
            );

            throw ValidationException::withMessages([
                'file' => 'Ukuran file melebihi batas 50 MB.',
            ]);
        }

        try {
            $document = DB::transaction(function () use ($file, $admin, $attributes, $originalName, $mimeType, $size) {
                $title = $this->resolveTitle($attributes['title'] ?? null, $originalName);
                $sourceId = $this->resolveSourceId($attributes['knowledge_source_id'] ?? null, $admin);

                $duplicate = KnowledgeDocument::query()
                    ->where('original_name', $originalName)
                    ->whereIn('status', [
                        KnowledgeDocument::STATUS_DRAFT,
                        KnowledgeDocument::STATUS_PROCESSING,
                        KnowledgeDocument::STATUS_ACTIVE,
                    ])
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'file' => 'Dokumen knowledge dengan nama yang sama sudah ada dan masih aktif.',
                    ]);
                }

                $directory = self::STORAGE_DIRECTORY.'/'.($sourceId ?? 'unsorted');
                $filename = time().'_'.$file->hashName();
                $filePath = $file->storeAs($directory, $filename);

                if (! $filePath) {
                    throw new \RuntimeException('Gagal menyimpan file knowledge ke storage.');
                }

                try {
                    $checksum = null;
                    $absolute = Storage::disk('local')->path($filePath);
                    if (is_string($absolute) && is_file($absolute)) {
                        $checksum = @hash_file('sha256', $absolute) ?: null;
                    }

                    return KnowledgeDocument::create([
                        'knowledge_source_id' => $sourceId,
                        'uploaded_by_id' => $admin->id,
                        'title' => $title,
                        'original_name' => $originalName,
                        'filename' => $filename,
                        'file_path' => $filePath,
                        'mime_type' => $mimeType,
                        'file_size_bytes' => $size,
                        'checksum_sha256' => $checksum,
                        'scope' => KnowledgeDocument::SCOPE_GLOBAL_INTERNAL,
                        'audience' => KnowledgeDocument::AUDIENCE_ALL_USERS,
                        'status' => KnowledgeDocument::STATUS_DRAFT,
                        'vector_namespace' => KnowledgeDocument::VECTOR_NAMESPACE,
                        'metadata' => [
                            'origin' => 'admin_upload',
                        ],
                        'notes' => isset($attributes['notes']) ? (string) $attributes['notes'] : null,
                    ]);
                } catch (\Throwable $e) {
                    Storage::delete($filePath);

                    throw $e;
                }
            });
        } catch (ValidationException $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
                userId: (int) $admin->id,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $mimeType,
                    'file_size_bytes' => $size,
                    'reason' => 'validation_failed',
                    'origin' => 'admin',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'validation_failed',
            );

            throw $e;
        } catch (\Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
                userId: (int) $admin->id,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $mimeType,
                    'file_size_bytes' => $size,
                    'reason' => 'storage_failed',
                    'origin' => 'admin',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'storage_failed',
            );

            throw $e;
        }

        $this->dispatchProcessing($document);

        $usageEvents->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: (int) $admin->id,
            metadata: [
                'document_id' => (int) $document->id,
                'document_extension' => $extension,
                'mime_type' => $mimeType,
                'file_size_bytes' => $size,
                'origin' => 'admin',
                'outcome' => 'uploaded',
            ],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $document,
        );

        return $document;
    }

    public function dispatchProcessing(KnowledgeDocument $document): void
    {
        $document->update([
            'status' => KnowledgeDocument::STATUS_PROCESSING,
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);

        ProcessKnowledgeDocument::dispatch($document);
    }

    public function activate(KnowledgeDocument $document, User $admin): KnowledgeDocument
    {
        $document->update([
            'status' => KnowledgeDocument::STATUS_ACTIVE,
            'archived_at' => null,
        ]);

        app(AIUsageEventService::class)->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: (int) $admin->id,
            metadata: [
                'document_id' => (int) $document->id,
                'origin' => 'admin',
                'outcome' => 'activated',
            ],
            subject: $document,
        );

        return $document->refresh();
    }

    public function archive(KnowledgeDocument $document, User $admin): KnowledgeDocument
    {
        $document->update([
            'status' => KnowledgeDocument::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);

        app(AIUsageEventService::class)->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: (int) $admin->id,
            metadata: [
                'document_id' => (int) $document->id,
                'origin' => 'admin',
                'outcome' => 'archived',
            ],
            subject: $document,
        );

        return $document->refresh();
    }

    public function reprocess(KnowledgeDocument $document, User $admin): KnowledgeDocument
    {
        $this->dispatchProcessing($document);

        app(AIUsageEventService::class)->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: (int) $admin->id,
            metadata: [
                'document_id' => (int) $document->id,
                'origin' => 'admin',
                'outcome' => 'reprocess_dispatched',
            ],
            subject: $document,
        );

        return $document->refresh();
    }

    public function delete(KnowledgeDocument $document, User $admin): bool
    {
        $documentId = (int) $document->id;

        $this->cleanupVectors($document);
        $this->deleteStoredFile($document);

        $deleted = $document->delete();

        app(AIUsageEventService::class)->completed(
            feature: AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            userId: (int) $admin->id,
            metadata: [
                'document_id' => $documentId,
                'origin' => 'admin',
                'outcome' => 'deleted',
            ],
        );

        return (bool) $deleted;
    }

    public function recordProcessingSuccess(KnowledgeDocument $document, ?string $provider, ?int $chunkCount, ?int $successfulChunks, ?int $failedChunks): void
    {
        $document->update([
            'status' => KnowledgeDocument::STATUS_ACTIVE,
            'processed_at' => now(),
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);

        KnowledgeChunk::updateOrCreate(
            ['knowledge_document_id' => $document->id],
            [
                'chunk_count' => max(0, (int) $chunkCount),
                'successful_chunks' => max(0, (int) $successfulChunks),
                'failed_chunks' => max(0, (int) $failedChunks),
                'embedding_provider' => $provider,
                'last_synced_at' => now(),
            ]
        );
    }

    public function recordProcessingFailure(KnowledgeDocument $document, string $errorCode, ?string $errorMessage = null): void
    {
        $document->update([
            'status' => KnowledgeDocument::STATUS_ERROR,
            'failed_at' => now(),
            'error_code' => substr($errorCode, 0, 64),
            'error_message' => $errorMessage !== null ? substr($errorMessage, 0, 1000) : null,
        ]);
    }

    private function cleanupVectors(KnowledgeDocument $document): void
    {
        $base = rtrim((string) config('services.ai_document_service.url', config('services.ai_service.url', 'http://127.0.0.1:8001')), '/');
        $token = config('services.ai_document_service.token', config('services.ai_service.token'));
        $url = $base.'/api/knowledge/'.urlencode($document->original_name).'?'.http_build_query([
            'document_id' => (string) $document->id,
            'cleanup_legacy' => 'true',
        ]);

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer '.$token])->delete($url);

            if (! $response->successful() && $response->status() !== 404) {
                logger()->warning('Knowledge vector deletion failed, proceeding anyway', [
                    'document_id' => $document->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            logger()->warning('Knowledge vector deletion HTTP error, proceeding anyway', [
                'document_id' => $document->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function deleteStoredFile(KnowledgeDocument $document): void
    {
        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }
    }

    private function resolveTitle(?string $rawTitle, string $originalName): string
    {
        $title = trim((string) $rawTitle);

        if ($title === '') {
            $title = pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName;
        }

        return mb_substr($title, 0, 191);
    }

    private function resolveSourceId(mixed $sourceId, User $admin): ?int
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        if (is_numeric($sourceId)) {
            $source = KnowledgeSource::query()->find((int) $sourceId);

            return $source?->id;
        }

        if (is_string($sourceId)) {
            $source = KnowledgeSource::query()
                ->firstOrCreate(
                    ['slug' => KnowledgeSource::generateUniqueSlug($sourceId)],
                    ['name' => $sourceId, 'created_by_id' => $admin->id]
                );

            return $source->id;
        }

        return null;
    }
}
