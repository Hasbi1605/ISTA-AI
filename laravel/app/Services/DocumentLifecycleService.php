<?php

namespace App\Services;

use App\Jobs\ProcessDocument;
use App\Jobs\RenderDocumentPreview;
use App\Models\AIUsageEvent;
use App\Models\CloudStorageFile;
use App\Models\Document;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use App\Services\CloudStorage\GoogleDriveService;
use App\Services\Documents\DocumentPreviewRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DocumentLifecycleService
{
    public const MAX_DOCUMENTS_PER_USER = 10;

    public const MAX_DOCUMENT_SIZE_KILOBYTES = 51_200;

    public const MAX_DOCUMENT_SIZE_BYTES = self::MAX_DOCUMENT_SIZE_KILOBYTES * 1024;

    /**
     * Upload and initiate document processing
     *
     * @throws ValidationException
     * @throws \Exception
     */
    public function uploadDocument(UploadedFile $file, int $userId, array $sourceAttributes = []): Document
    {
        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();
        $originalName = $file->getClientOriginalName();
        $detectedMimeType = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($detectedMimeType, Document::attachmentMimeTypes(), true)) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
                userId: $userId,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $detectedMimeType,
                    'reason' => 'invalid_mime_type',
                    'origin' => 'local',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'invalid_mime_type',
            );

            throw ValidationException::withMessages([
                'file' => 'Tipe MIME file tidak valid. Gunakan PDF, DOCX, XLSX, atau CSV.',
            ]);
        }

        if (! in_array($extension, Document::attachmentFileExtensions(), true) || ($detectedMimeType === 'text/plain' && $extension !== 'csv')) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
                userId: $userId,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $detectedMimeType,
                    'reason' => 'invalid_extension',
                    'origin' => 'local',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'invalid_extension',
            );

            throw ValidationException::withMessages([
                'file' => 'Format file tidak valid. Gunakan PDF, DOCX, XLSX, atau CSV.',
            ]);
        }

        $fileSizeBytes = $file->getSize();

        if ($fileSizeBytes !== null && $fileSizeBytes > self::MAX_DOCUMENT_SIZE_BYTES) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
                userId: $userId,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $detectedMimeType,
                    'file_size_bytes' => $fileSizeBytes,
                    'reason' => 'file_too_large',
                    'origin' => 'local',
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
            $document = $this->storeUploadedDocumentRecord(
                file: $file,
                userId: $userId,
                sourceAttributes: $sourceAttributes,
                originalName: $originalName,
                detectedMimeType: $detectedMimeType,
                fileSizeBytes: $fileSizeBytes,
                wrapInTransaction: true,
            );
        } catch (ValidationException $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
                userId: $userId,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $detectedMimeType,
                    'file_size_bytes' => $fileSizeBytes,
                    'reason' => 'validation_failed',
                    'origin' => 'local',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'validation_failed',
            );

            throw $e;
        } catch (\Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
                userId: $userId,
                metadata: [
                    'document_extension' => $extension,
                    'mime_type' => $detectedMimeType,
                    'file_size_bytes' => $fileSizeBytes,
                    'reason' => 'storage_failed',
                    'origin' => 'local',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'storage_failed',
            );

            throw $e;
        }

        try {
            $this->dispatchPreviewRendering($document);
        } catch (\Throwable $e) {
            logger()->warning("Preview dispatch failed for document {$document->id}, proceeding: ".$e->getMessage());
        }
        $this->dispatchProcessing($document);

        $usageEvents->completed(
            feature: AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
            userId: $userId,
            metadata: [
                'document_id' => (int) $document->id,
                'document_extension' => $extension,
                'mime_type' => $detectedMimeType,
                'file_size_bytes' => $fileSizeBytes,
                'origin' => 'local',
            ],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $document,
        );

        return $document;
    }

    /**
     * Download a file from Google Drive and ingest it into the normal document pipeline.
     */
    public function ingestFromCloud(User $user, string $provider, string $externalId): Document
    {
        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();

        if ($provider !== 'google_drive') {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'reason' => 'unsupported_provider',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'unsupported_provider',
            );

            throw new InvalidArgumentException('Provider cloud storage tidak didukung.');
        }

        $existingDocument = Document::query()
            ->where('user_id', $user->id)
            ->where('source_provider', $provider)
            ->where('source_external_id', $externalId)
            ->first();

        if ($existingDocument !== null) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'reason' => 'already_imported',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'already_imported',
                subject: $existingDocument,
            );

            throw ValidationException::withMessages([
                'file' => 'File Drive ini sudah pernah diproses di akun Anda.',
            ]);
        }

        /** @var GoogleDriveService $driveService */
        $driveService = app(GoogleDriveService::class);

        try {
            $download = $driveService->downloadToTemp($externalId);
        } catch (\Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'reason' => 'drive_download_failed',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'drive_download_failed',
            );

            throw $e;
        }

        $tempPath = $download['path'] ?? null;

        if (! is_string($tempPath) || $tempPath === '' || ! is_file($tempPath)) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'reason' => 'drive_temp_unavailable',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'drive_temp_unavailable',
            );

            throw new \RuntimeException('Gagal menyiapkan file sementara dari Google Drive.');
        }

        $originalName = (string) ($download['original_name'] ?? basename($tempPath));
        $mimeType = (string) ($download['mime_type'] ?? 'application/octet-stream');
        $sourceSyncedAt = now();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: '');

        if (! in_array($extension, Document::attachmentFileExtensions(), true) || ($mimeType === 'text/plain' && $extension !== 'csv')) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'mime_type' => $mimeType,
                    'document_extension' => $extension,
                    'reason' => 'invalid_extension',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'invalid_extension',
            );

            @unlink($tempPath);

            throw ValidationException::withMessages([
                'file' => 'Format file dari Google Drive tidak didukung. Gunakan PDF, DOCX, XLSX, atau CSV.',
            ]);
        }

        // Validate MIME type against the same allowlist used for manual uploads.
        // GoogleDriveService::downloadToTemp() already enforces size and rejects
        // native Google Docs formats; this adds the MIME-type gate so Drive imports
        // cannot bypass Document::attachmentMimeTypes() checks.
        if (! in_array($mimeType, Document::attachmentMimeTypes(), true)) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'mime_type' => $mimeType,
                    'document_extension' => $extension,
                    'reason' => 'invalid_mime_type',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'invalid_mime_type',
            );

            @unlink($tempPath);

            throw ValidationException::withMessages([
                'file' => 'Tipe file dari Google Drive tidak didukung. Gunakan PDF, DOCX, XLSX, atau CSV.',
            ]);
        }

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $originalName,
                $mimeType,
                null,
                true
            );

            $document = DB::transaction(function () use ($uploadedFile, $user, $provider, $externalId, $sourceSyncedAt, $originalName, $mimeType, $download) {
                $document = $this->storeUploadedDocumentRecord(
                    file: $uploadedFile,
                    userId: $user->id,
                    sourceAttributes: [
                        'source_provider' => $provider,
                        'source_external_id' => $externalId,
                        'source_synced_at' => $sourceSyncedAt,
                    ],
                    originalName: $originalName,
                    detectedMimeType: $mimeType,
                    fileSizeBytes: $uploadedFile->getSize(),
                    wrapInTransaction: false,
                );

                CloudStorageFile::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'provider' => $provider,
                        'external_id' => $externalId,
                    ],
                    [
                        'direction' => CloudStorageFile::DIRECTION_IMPORT,
                        'local_type' => Document::class,
                        'local_id' => $document->id,
                        'name' => $originalName,
                        'mime_type' => $mimeType,
                        'web_view_link' => $download['web_view_link'] ?? null,
                        'folder_external_id' => $download['folder_external_id'] ?? null,
                        'size_bytes' => $download['size_bytes'] ?? null,
                        'synced_at' => $sourceSyncedAt,
                    ]
                );

                return $document;
            });

            try {
                $this->dispatchPreviewRendering($document);
            } catch (\Throwable $e) {
                logger()->warning("Preview dispatch failed for document {$document->id}, proceeding: ".$e->getMessage());
            }
            $this->dispatchProcessing($document);

            $usageEvents->completed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'document_id' => (int) $document->id,
                    'document_extension' => $extension,
                    'mime_type' => $mimeType,
                    'provider' => $provider,
                    'size_bytes' => $download['size_bytes'] ?? null,
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                subject: $document,
            );

            return $document;
        } catch (\Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT,
                userId: (int) $user->id,
                metadata: [
                    'provider' => $provider,
                    'mime_type' => $mimeType,
                    'document_extension' => $extension,
                    'reason' => 'ingest_failed',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'ingest_failed',
            );

            throw $e;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sourceAttributes
     */
    private function storeUploadedDocumentRecord(
        UploadedFile $file,
        int $userId,
        array $sourceAttributes,
        string $originalName,
        string $detectedMimeType,
        ?int $fileSizeBytes,
        bool $wrapInTransaction = true,
    ): Document {
        $callback = function () use ($file, $userId, $sourceAttributes, $originalName, $detectedMimeType, $fileSizeBytes) {
            $count = Document::where('user_id', $userId)
                ->lockForUpdate()
                ->count();
            if ($count >= self::MAX_DOCUMENTS_PER_USER) {
                throw ValidationException::withMessages([
                    'file' => 'Limit kuota dokumen tercapai (Maksimal 10 dokumen).',
                ]);
            }

            $duplicateExists = Document::where('user_id', $userId)
                ->where('original_name', $originalName)
                ->lockForUpdate()
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'file' => 'File dengan nama yang sama sudah pernah diunggah.',
                ]);
            }

            $filename = time().'_'.$file->hashName();
            $filePath = $file->storeAs('documents/'.$userId, $filename);

            if (! $filePath) {
                throw new \Exception('Gagal menyimpan file ke storage.');
            }

            try {
                return Document::create([
                    'user_id' => $userId,
                    'filename' => $filename,
                    'original_name' => $originalName,
                    'file_path' => $filePath,
                    'source_provider' => $sourceAttributes['source_provider'] ?? 'local',
                    'source_external_id' => $sourceAttributes['source_external_id'] ?? null,
                    'source_synced_at' => $sourceAttributes['source_synced_at'] ?? null,
                    'mime_type' => $detectedMimeType,
                    'file_size_bytes' => $fileSizeBytes,
                    'status' => 'pending',
                ]);
            } catch (\Throwable $e) {
                if ($filePath) {
                    Storage::delete($filePath);
                }

                throw $e;
            }
        };

        return $wrapInTransaction ? DB::transaction($callback) : $callback();
    }

    /**
     * Dispatch the job to process a document
     */
    public function dispatchProcessing(Document $document): void
    {
        ProcessDocument::dispatch($document);
    }

    /**
     * Dispatch the job to render a document preview.
     */
    public function dispatchPreviewRendering(Document $document): void
    {
        RenderDocumentPreview::dispatch($document);
    }

    /**
     * Delete document and perform cleanup (Vector DB, Storage, Database).
     * Hard delete — no soft-delete recycle bin (Opsi B, issue #159).
     *
     * @throws \Exception
     */
    public function deleteDocument(Document $document): bool
    {
        $this->cleanupDocumentArtifacts($document);

        return $document->delete();
    }

    /**
     * Delete multiple documents
     *
     * @param  iterable<Document>  $documents
     */
    public function deleteDocuments(iterable $documents): void
    {
        foreach ($documents as $document) {
            $this->deleteDocument($document);
        }
    }

    /**
     * Summarize a document, with readiness guard
     *
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function summarizeDocument(Document $document, AIService $aiService): array
    {
        if ($document->status !== 'ready') {
            throw new InvalidArgumentException('Dokumen belum selesai diproses. Tunggu hingga status menjadi "ready".');
        }

        return $aiService->summarizeDocument(
            $document->original_name,
            (string) $document->user_id,
            (string) $document->id
        );
    }

    private function cleanupDocumentArtifacts(Document $document): void
    {
        $this->deleteDocumentVectors($document);
        $this->deleteDocumentFile($document);
        $this->deleteDocumentPreview($document);
        $document->cloudStorageFiles()->delete();
    }

    private function deleteDocumentPreview(Document $document): void
    {
        try {
            app(DocumentPreviewRenderer::class)->deletePreview($document);
        } catch (\Throwable $e) {
            logger()->warning("Preview cleanup failed for document {$document->id}, proceeding anyway: ".$e->getMessage());
        }
    }

    private function deleteDocumentVectors(Document $document): void
    {
        // User-initiated delete: the document's filename is being permanently retired
        // so it is safe to also clean up legacy Chroma chunks that pre-date document_id
        // tracking (cleanup_legacy=true). This is distinct from ProcessDocument job
        // cleanup, which must NOT use legacy cleanup to avoid deleting a re-uploaded
        // same-filename document's vectors.
        $pythonUrl = rtrim((string) config('services.ai_document_service.url', config('services.ai_service.url', 'http://127.0.0.1:8001')), '/')
            .'/api/documents/'.urlencode($document->original_name)
            .'?'.http_build_query([
                'user_id' => (string) $document->user_id,
                'document_id' => (string) $document->id,
                'cleanup_legacy' => 'true',
            ]);
        $token = config('services.ai_document_service.token', config('services.ai_service.token'));

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->delete($pythonUrl);

            if (! $response->successful() && $response->status() !== 404) {
                logger()->warning("Vector deletion failed for {$document->original_name}, proceeding anyway: ".$response->body());
            }
        } catch (\Exception $e) {
            logger()->warning("Vector deletion HTTP request failed for {$document->original_name}, proceeding anyway: ".$e->getMessage());
        }
    }

    private function deleteDocumentFile(Document $document): void
    {
        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }
    }
}
