<?php

namespace App\Services\CloudStorage;

use App\Models\Document;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoogleDriveService
{
    private const GOOGLE_DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const GOOGLE_DRIVE_SHORTCUT_MIME = 'application/vnd.google-apps.shortcut';

    private const GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';

    public const MAX_IMPORT_FILE_SIZE_BYTES = 52_428_800;

    private const MAX_ANCESTOR_DEPTH = 100;

    private ?Client $client = null;

    private ?Drive $drive = null;

    public function isConfigured(): bool
    {
        return $this->rootFolderId() !== null && ($this->hasCentralOAuthConnection() || $this->loadCredentialPayload() !== null);
    }

    public function rootFolderId(): ?string
    {
        return $this->normalizeNullableStringConfig(config('services.google_drive.root_folder_id'));
    }

    public function sharedDriveId(): ?string
    {
        return $this->normalizeNullableStringConfig(config('services.google_drive.shared_drive_id'));
    }

    public function impersonatedUserEmail(): ?string
    {
        return $this->normalizeNullableStringConfig(config('services.google_drive.impersonated_user_email'));
    }

    public function canUploadWithConfiguredAccount(): bool
    {
        return $this->hasCentralOAuthConnection() || $this->sharedDriveId() !== null || $this->impersonatedUserEmail() !== null;
    }

    public function defaultUploadFolderName(): string
    {
        return $this->normalizeStringConfig(config('services.google_drive.default_upload_folder_name'), 'ISTA AI');
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, next_page_token: ?string, folder_id: string, folder_name: string}
     */
    public function listFiles(?string $parentFolderId = null, ?string $query = null, ?string $pageToken = null, int $pageSize = 20): array
    {
        $folderId = $this->resolveFolderId($parentFolderId);
        $conditions = [
            'trashed = false',
            sprintf("'%s' in parents", $this->escapeQueryValue($folderId)),
        ];

        $searchTerm = trim((string) $query);
        if ($searchTerm !== '') {
            $conditions[] = sprintf("name contains '%s'", $this->escapeQueryValue($searchTerm));
        }

        $options = [
            'q' => implode(' and ', $conditions),
            'pageSize' => max(1, min($pageSize, 100)),
            'fields' => 'nextPageToken, files(id, name, mimeType, size, webViewLink, modifiedTime, parents, shortcutDetails)',
            'orderBy' => 'folder,name',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ];

        if ($pageToken !== null && $pageToken !== '') {
            $options['pageToken'] = $pageToken;
        }

        $sharedDriveId = $this->sharedDriveId();

        if ($sharedDriveId !== null) {
            $options['driveId'] = $sharedDriveId;
            $options['corpora'] = 'drive';
        } else {
            $options['corpora'] = 'user';
        }

        $response = $this->listDriveFilesResponse($options);
        $items = [];

        foreach ($response->getFiles() as $file) {
            $mimeType = (string) ($file->getMimeType() ?? '');
            $isFolder = $mimeType === self::GOOGLE_DRIVE_FOLDER_MIME;
            $isShortcut = $mimeType === self::GOOGLE_DRIVE_SHORTCUT_MIME;
            $isGoogleWorkspaceFile = str_starts_with($mimeType, 'application/vnd.google-apps.') && ! $isShortcut;
            $isSupportedImportFile = $this->isSupportedImportFile((string) $file->getName(), $mimeType);
            $shortcutDetails = $file->getShortcutDetails();
            $shortcutTargetId = is_object($shortcutDetails) && method_exists($shortcutDetails, 'getTargetId')
                ? trim((string) $shortcutDetails->getTargetId())
                : '';
            $shortcutTargetMimeType = is_object($shortcutDetails) && method_exists($shortcutDetails, 'getTargetMimeType')
                ? trim((string) $shortcutDetails->getTargetMimeType())
                : '';

            $items[] = [
                'id' => (string) $file->getId(),
                'name' => (string) $file->getName(),
                'mime_type' => $mimeType,
                'shortcut_target_id' => $shortcutTargetId !== '' ? $shortcutTargetId : null,
                'shortcut_target_mime_type' => $shortcutTargetMimeType !== '' ? $shortcutTargetMimeType : null,
                'web_view_link' => $file->getWebViewLink(),
                'modified_time' => $file->getModifiedTime(),
                'size_bytes' => $file->getSize() !== null ? (int) $file->getSize() : null,
                'parents' => $file->getParents() ?? [],
                'is_folder' => $isFolder,
                'is_google_workspace_file' => $isGoogleWorkspaceFile && ! $isFolder,
                'is_shortcut' => $isShortcut,
                'is_processable' => ! $isFolder && ! $isGoogleWorkspaceFile && ! $isShortcut && $isSupportedImportFile,
                'unsupported_reason' => $this->unsupportedReasonForFile($isFolder, $isGoogleWorkspaceFile, $isShortcut, $isSupportedImportFile),
            ];
        }

        return [
            'items' => $items,
            'next_page_token' => $response->getNextPageToken() ?: null,
            'folder_id' => $folderId,
            'folder_name' => $folderId,
        ];
    }

    /**
     * @return array{external_id: string, original_name: string, mime_type: string, size_bytes: ?int, web_view_link: ?string, folder_external_id: ?string, path: string}
     */
    public function downloadToTemp(string $fileId): array
    {
        $metadata = $this->getFileMetadata($fileId);
        $this->ensureFileMetadataWithinRoot($metadata);
        $mimeType = (string) ($metadata->getMimeType() ?? '');

        if ($mimeType === self::GOOGLE_DRIVE_FOLDER_MIME) {
            throw new RuntimeException('Folder Google Drive tidak bisa diunduh sebagai dokumen.');
        }

        if ($mimeType === self::GOOGLE_DRIVE_SHORTCUT_MIME) {
            throw new RuntimeException($this->unsupportedShortcutMessage());
        }

        if (str_starts_with($mimeType, 'application/vnd.google-apps.')) {
            throw new RuntimeException('File Google Docs/Sheets/Slides belum didukung pada MVP ini. Gunakan PDF, DOCX, XLSX, atau CSV yang berupa file binary.');
        }

        if (! $this->isSupportedImportFile((string) $metadata->getName(), $mimeType)) {
            throw new RuntimeException($this->unsupportedImportFileMessage());
        }

        $this->ensureFileSizeIsAllowed($metadata->getSize() !== null ? (int) $metadata->getSize() : null);

        $directory = Storage::disk('local')->path('tmp/cloud/google-drive');

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Gagal menyiapkan folder sementara untuk Google Drive.');
        }

        $tempPath = tempnam($directory, 'gdrive_');

        if ($tempPath === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk Google Drive.');
        }

        try {
            $bytesWritten = $this->writeDownloadedFileToPath(
                $this->downloadDriveBinaryResponse($fileId),
                $tempPath,
            );
            $this->ensureFileSizeIsAllowed($metadata->getSize() !== null ? (int) $metadata->getSize() : $bytesWritten);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }

        return [
            'external_id' => (string) $metadata->getId(),
            'original_name' => (string) $metadata->getName(),
            'mime_type' => $mimeType,
            'size_bytes' => $metadata->getSize() !== null ? (int) $metadata->getSize() : null,
            'web_view_link' => $metadata->getWebViewLink(),
            'folder_external_id' => $metadata->getParents()[0] ?? null,
            'path' => $tempPath,
        ];
    }

    /**
     * @return array{external_id: string, name: string, mime_type: string, web_view_link: ?string, folder_external_id: ?string, size_bytes: ?int}
     */
    public function uploadFromPath(string $localPath, string $fileName, string $mimeType, ?string $parentFolderId = null): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('File lokal untuk upload ke Google Drive tidak ditemukan.');
        }

        $this->ensureUploadTargetSupportsServiceAccount();

        $folderId = $this->resolveUploadFolderId($parentFolderId);
        $contents = file_get_contents($localPath);

        if ($contents === false) {
            throw new RuntimeException('Gagal membaca file lokal untuk upload ke Google Drive.');
        }

        $metadata = new DriveFile([
            'name' => $fileName,
            'parents' => [$folderId],
            'mimeType' => $mimeType,
            'appProperties' => [
                'ista_ai_provider' => 'google_drive',
                'ista_ai_direction' => 'export',
            ],
        ]);

        $response = $this->callDriveApi(fn () => $this->drive()->files->create($metadata, [
            'data' => $contents,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'supportsAllDrives' => true,
            'fields' => 'id,name,mimeType,size,webViewLink,parents',
        ]));

        return [
            'external_id' => (string) $response->getId(),
            'name' => (string) $response->getName(),
            'mime_type' => (string) ($response->getMimeType() ?? $mimeType),
            'web_view_link' => $response->getWebViewLink(),
            'folder_external_id' => $response->getParents()[0] ?? $folderId,
            'size_bytes' => $response->getSize() !== null ? (int) $response->getSize() : null,
        ];
    }

    public function resolveUploadFolderId(?string $parentFolderId = null): string
    {
        $folderId = $this->resolveFolderId($parentFolderId);
        $uploadFolderName = $this->defaultUploadFolderName();

        $existingFolder = $this->findChildFolderByName($folderId, $uploadFolderName);

        if ($existingFolder !== null) {
            return $existingFolder;
        }

        $folder = new DriveFile([
            'name' => $uploadFolderName,
            'mimeType' => self::GOOGLE_DRIVE_FOLDER_MIME,
            'parents' => [$folderId],
        ]);

        $created = $this->createDriveFileRecord($folder, [
            'supportsAllDrives' => true,
            'fields' => 'id',
        ]);

        return (string) $created->getId();
    }

    public function getFileMetadata(string $fileId): DriveFile
    {
        return $this->fetchDriveFileRecord($fileId, 'id,name,mimeType,size,webViewLink,parents,modifiedTime,shortcutDetails');
    }

    public function describeUploadFailure(\Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());
        $details = $this->extractGoogleDriveErrorDetails($throwable, $message);

        if ($this->isSharedDriveQuotaError($throwable, $message, $details)) {
            return $this->unsupportedServiceAccountUploadMessage();
        }

        if ($details['message'] !== null && $details['message'] !== '') {
            return $this->normalizeHumanReadableMessage($details['message']);
        }

        if ($message !== '') {
            if ($this->looksLikeJsonPayload($message)) {
                return 'Upload ke Google Drive gagal.';
            }

            return $this->normalizeHumanReadableMessage($message);
        }

        return 'Upload ke Google Drive gagal.';
    }

    private function drive(): Drive
    {
        if ($this->drive !== null) {
            return $this->drive;
        }

        $client = $this->client();
        $this->drive = new Drive($client);

        return $this->drive;
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function callDriveApi(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException($this->describeUploadFailure($e), 0, $e);
        }
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $oauthService = app(GoogleDriveOAuthService::class);

        if ($oauthService->hasCentralConnection()) {
            $this->client = $oauthService->clientForCentralConnection();

            return $this->client;
        }

        $payload = $this->loadCredentialPayload();

        if ($payload === null) {
            throw new RuntimeException('Konfigurasi service account Google Drive belum tersedia.');
        }

        $client = new Client;
        $client->setApplicationName(config('app.name', 'ISTA AI'));
        $client->setScopes([self::GOOGLE_DRIVE_SCOPE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setAuthConfig($payload);

        $impersonatedUserEmail = $this->impersonatedUserEmail();

        if ($impersonatedUserEmail !== null) {
            $client->setSubject($impersonatedUserEmail);
        }

        $this->client = $client;

        return $this->client;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCredentialPayload(): ?array
    {
        $json = $this->normalizeStringConfig(config('services.google_drive.service_account_json'));
        $path = $this->normalizeStringConfig(config('services.google_drive.service_account_path'));

        if ($json !== '') {
            $decoded = $this->decodeCredentialString($json);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        if ($path !== '' && is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $decoded = $this->decodeCredentialString($contents);

                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeCredentialString(string $value): ?array
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return null;
        }

        if (! str_starts_with($candidate, '{') && ! str_starts_with($candidate, '[')) {
            $decodedBase64 = base64_decode($candidate, true);

            if ($decodedBase64 !== false) {
                $candidate = trim($decodedBase64);
            }
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveFolderId(?string $folderId = null): string
    {
        $rootFolderId = $this->rootFolderId();
        $candidate = $this->normalizeStringConfig($folderId ?? $rootFolderId ?? '');

        if ($candidate === '') {
            throw new RuntimeException('Root folder Google Drive belum dikonfigurasi.');
        }

        if ($rootFolderId !== null && $candidate !== $rootFolderId) {
            $this->ensureFolderWithinRoot($candidate);
        }

        return $candidate;
    }

    private function findChildFolderByName(string $parentFolderId, string $folderName): ?string
    {
        $options = [
            'q' => implode(' and ', [
                'trashed = false',
                sprintf("mimeType = '%s'", self::GOOGLE_DRIVE_FOLDER_MIME),
                sprintf("name = '%s'", $this->escapeQueryValue($folderName)),
                sprintf("'%s' in parents", $this->escapeQueryValue($parentFolderId)),
            ]),
            'pageSize' => 1,
            'fields' => 'files(id)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ];

        $sharedDriveId = $this->sharedDriveId();
        if ($sharedDriveId !== null) {
            $options['corpora'] = 'drive';
            $options['driveId'] = $sharedDriveId;
        } else {
            $options['corpora'] = 'user';
        }

        $response = $this->listDriveFilesResponse($options);

        $files = $response->getFiles();

        if ($files === []) {
            return null;
        }

        return (string) $files[0]->getId();
    }

    private function escapeQueryValue(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    protected function listDriveFilesResponse(array $options): mixed
    {
        return $this->callDriveApi(fn () => $this->drive()->files->listFiles($options));
    }

    protected function fetchDriveFileRecord(string $fileId, string $fields): DriveFile
    {
        return $this->callDriveApi(fn () => $this->drive()->files->get($fileId, [
            'fields' => $fields,
            'supportsAllDrives' => true,
        ]));
    }

    protected function downloadDriveBinaryResponse(string $fileId): mixed
    {
        return $this->callDriveApi(fn () => $this->drive()->files->get($fileId, [
            'alt' => 'media',
            'supportsAllDrives' => true,
        ]));
    }

    protected function createDriveFileRecord(DriveFile $metadata, array $options): DriveFile
    {
        return $this->callDriveApi(fn () => $this->drive()->files->create($metadata, $options));
    }

    private function ensureFolderWithinRoot(string $folderId): void
    {
        $metadata = $this->fetchDriveFileRecord($folderId, 'id,mimeType,parents');

        if ((string) ($metadata->getMimeType() ?? '') !== self::GOOGLE_DRIVE_FOLDER_MIME) {
            throw new RuntimeException('Folder Google Drive tidak valid.');
        }

        $this->ensureAncestorsLeadToRoot(
            (string) $metadata->getId(),
            $metadata->getParents() ?? [],
            'Folder Google Drive berada di luar folder kantor yang diizinkan.',
        );
    }

    private function ensureFileMetadataWithinRoot(DriveFile $metadata): void
    {
        $this->ensureAncestorsLeadToRoot(
            (string) $metadata->getId(),
            $metadata->getParents() ?? [],
            'File Google Drive berada di luar folder kantor yang diizinkan.',
        );
    }

    /**
     * @param  array<int, string>  $parentIds
     */
    private function ensureAncestorsLeadToRoot(string $itemId, array $parentIds, string $errorMessage): void
    {
        $rootFolderId = $this->rootFolderId();

        if ($rootFolderId === null || $rootFolderId === '') {
            throw new RuntimeException('Root folder Google Drive belum dikonfigurasi.');
        }

        if ($itemId === $rootFolderId) {
            return;
        }

        $queue = array_values(array_filter(array_map(
            fn (mixed $parentId): string => trim((string) $parentId),
            $parentIds
        )));
        $visited = [$itemId => true];
        $depth = 0;

        while ($queue !== []) {
            $parentId = array_shift($queue);

            if ($parentId === $rootFolderId) {
                return;
            }

            if ($parentId === '' || isset($visited[$parentId])) {
                continue;
            }

            $visited[$parentId] = true;
            $depth++;

            if ($depth > self::MAX_ANCESTOR_DEPTH) {
                break;
            }

            $parentMetadata = $this->fetchDriveFileRecord($parentId, 'id,parents');

            foreach ($parentMetadata->getParents() ?? [] as $ancestorId) {
                $ancestorId = trim((string) $ancestorId);

                if ($ancestorId !== '' && ! isset($visited[$ancestorId])) {
                    $queue[] = $ancestorId;
                }
            }
        }

        throw new RuntimeException($errorMessage);
    }

    private function ensureFileSizeIsAllowed(?int $sizeBytes): void
    {
        if ($sizeBytes !== null && $sizeBytes > self::MAX_IMPORT_FILE_SIZE_BYTES) {
            throw new RuntimeException($this->oversizedFileMessage());
        }
    }

    private function oversizedFileMessage(): string
    {
        return 'Ukuran file Google Drive melebihi batas 50 MB.';
    }

    private function unsupportedShortcutMessage(): string
    {
        return 'Shortcut Google Drive belum didukung. Buka file target langsung lalu pilih file PDF, DOCX, XLSX, atau CSV.';
    }

    private function unsupportedImportFileMessage(): string
    {
        return 'Format file Google Drive tidak didukung. Gunakan PDF, DOCX, XLSX, atau CSV.';
    }

    private function isSupportedImportFile(string $name, string $mimeType): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');

        if (! in_array($extension, Document::attachmentFileExtensions(), true)) {
            return false;
        }

        if (! in_array($mimeType, Document::attachmentMimeTypes(), true)) {
            return false;
        }

        return $mimeType !== 'text/plain' || $extension === 'csv';
    }

    private function unsupportedReasonForFile(bool $isFolder, bool $isGoogleWorkspaceFile, bool $isShortcut, bool $isSupportedImportFile): ?string
    {
        if ($isFolder) {
            return null;
        }

        if ($isShortcut) {
            return $this->unsupportedShortcutMessage();
        }

        if ($isGoogleWorkspaceFile) {
            return 'File Google Docs/Sheets/Slides belum didukung pada MVP ini. Gunakan PDF, DOCX, XLSX, atau CSV yang berupa file binary.';
        }

        return $isSupportedImportFile ? null : $this->unsupportedImportFileMessage();
    }

    private function writeDownloadedFileToPath(mixed $response, string $tempPath): int
    {
        if (is_object($response) && method_exists($response, 'getBody')) {
            $body = $response->getBody();

            if (is_object($body) && method_exists($body, 'read')) {
                return $this->writeStreamBodyToPath($body, $tempPath);
            }

            return $this->writeStringBodyToPath((string) $body, $tempPath);
        }

        if (is_string($response)) {
            return $this->writeStringBodyToPath($response, $tempPath);
        }

        throw new RuntimeException('Gagal mengunduh file dari Google Drive.');
    }

    private function writeStringBodyToPath(string $contents, string $tempPath): int
    {
        if ($contents === '') {
            throw new RuntimeException('Gagal mengunduh file dari Google Drive.');
        }

        $this->ensureFileSizeIsAllowed(strlen($contents));

        if (file_put_contents($tempPath, $contents) === false) {
            throw new RuntimeException('Gagal menyimpan file sementara Google Drive.');
        }

        return strlen($contents);
    }

    private function writeStreamBodyToPath(object $stream, string $tempPath): int
    {
        $handle = fopen($tempPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Gagal menyimpan file sementara Google Drive.');
        }

        $written = 0;

        try {
            while (true) {
                $chunk = $stream->read(1024 * 1024);

                if (! is_string($chunk) || $chunk === '') {
                    break;
                }

                $bytes = fwrite($handle, $chunk);

                if ($bytes === false) {
                    throw new RuntimeException('Gagal menyimpan file sementara Google Drive.');
                }

                $written += $bytes;
                $this->ensureFileSizeIsAllowed($written);
            }
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            throw new RuntimeException('Gagal mengunduh file dari Google Drive.');
        }

        return $written;
    }

    private function normalizeStringConfig(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }

        $normalized = trim((string) $value);

        if (strlen($normalized) >= 2) {
            $quote = $normalized[0];
            if (($quote === '"' || $quote === "'") && $normalized[strlen($normalized) - 1] === $quote) {
                $normalized = substr($normalized, 1, -1);
            }
        }

        return $normalized === '' ? $default : $normalized;
    }

    private function normalizeNullableStringConfig(mixed $value): ?string
    {
        $normalized = $this->normalizeStringConfig($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function ensureUploadTargetSupportsServiceAccount(): void
    {
        if ($this->canUploadWithConfiguredAccount()) {
            return;
        }

        throw new RuntimeException($this->unsupportedServiceAccountUploadMessage());
    }

    private function unsupportedServiceAccountUploadMessage(): string
    {
        return 'Upload ke Google Drive belum aktif. Hubungkan akun Google Drive pusat lewat OAuth atau gunakan Shared Drive kantor.';
    }

    private function hasCentralOAuthConnection(): bool
    {
        try {
            return app(GoogleDriveOAuthService::class)->hasCentralConnection();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{message: ?string, reason: ?string}
     */
    private function extractGoogleDriveErrorDetails(\Throwable $throwable, string $message): array
    {
        $details = [
            'message' => null,
            'reason' => null,
        ];

        if ($throwable instanceof GoogleServiceException) {
            foreach ($throwable->getErrors() ?? [] as $error) {
                if (! is_array($error)) {
                    continue;
                }

                if ($details['message'] === null && isset($error['message']) && is_string($error['message']) && trim($error['message']) !== '') {
                    $details['message'] = trim($error['message']);
                }

                if ($details['reason'] === null && isset($error['reason']) && is_string($error['reason']) && trim($error['reason']) !== '') {
                    $details['reason'] = trim($error['reason']);
                }

                if ($details['message'] !== null && $details['reason'] !== null) {
                    break;
                }
            }
        }

        $parsedMessage = $this->parseGoogleDriveJsonMessage($message);
        if ($details['message'] === null && $parsedMessage !== null) {
            $details['message'] = $parsedMessage;
        }

        if ($details['reason'] === null) {
            $parsedReason = $this->parseGoogleDriveJsonReason($message);
            if ($parsedReason !== null) {
                $details['reason'] = $parsedReason;
            }
        }

        return $details;
    }

    private function isSharedDriveQuotaError(\Throwable $throwable, string $message, array $details): bool
    {
        $haystack = strtolower($message.' '.($details['message'] ?? '').' '.($details['reason'] ?? ''));

        if ($throwable instanceof GoogleServiceException) {
            foreach ($throwable->getErrors() ?? [] as $error) {
                if (! is_array($error)) {
                    continue;
                }

                if (isset($error['message']) && is_string($error['message'])) {
                    $haystack .= ' '.strtolower($error['message']);
                }

                if (isset($error['reason']) && is_string($error['reason'])) {
                    $haystack .= ' '.strtolower($error['reason']);
                }
            }
        }

        return str_contains($haystack, 'storagequotaexceeded')
            || str_contains($haystack, 'service accounts do not have storage quota')
            || str_contains($haystack, 'do not have storage quota');
    }

    private function parseGoogleDriveJsonMessage(string $message): ?string
    {
        $candidate = trim($message);

        if ($candidate === '' || (! str_starts_with($candidate, '{') && ! str_starts_with($candidate, '['))) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        if (isset($error['message']) && is_string($error['message']) && trim($error['message']) !== '') {
            return trim($error['message']);
        }

        if (! isset($error['errors']) || ! is_array($error['errors'])) {
            return null;
        }

        foreach ($error['errors'] as $errorItem) {
            if (! is_array($errorItem)) {
                continue;
            }

            if (isset($errorItem['message']) && is_string($errorItem['message']) && trim($errorItem['message']) !== '') {
                return trim($errorItem['message']);
            }
        }

        return null;
    }

    private function parseGoogleDriveJsonReason(string $message): ?string
    {
        $candidate = trim($message);

        if ($candidate === '' || (! str_starts_with($candidate, '{') && ! str_starts_with($candidate, '['))) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (! is_array($error) || ! isset($error['errors']) || ! is_array($error['errors'])) {
            return null;
        }

        foreach ($error['errors'] as $errorItem) {
            if (! is_array($errorItem)) {
                continue;
            }

            if (isset($errorItem['reason']) && is_string($errorItem['reason']) && trim($errorItem['reason']) !== '') {
                return trim($errorItem['reason']);
            }
        }

        return null;
    }

    private function normalizeHumanReadableMessage(string $message): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($message));

        if (! is_string($normalized) || $normalized === '') {
            return 'Upload ke Google Drive gagal.';
        }

        return $normalized;
    }

    private function looksLikeJsonPayload(string $message): bool
    {
        $candidate = trim($message);

        return $candidate !== '' && (str_starts_with($candidate, '{') || str_starts_with($candidate, '['));
    }
}
