<?php

namespace Tests\Unit\Services\CloudStorage;

use App\Services\CloudStorage\GoogleDriveService;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileShortcutDetails;
use Google\Service\Exception as GoogleServiceException;
use Tests\TestCase;

class GoogleDriveServiceTest extends TestCase
{
    public function test_service_reports_configured_when_credentials_and_root_folder_exist(): void
    {
        config([
            'services.google_drive.service_account_json' => json_encode([
                'type' => 'service_account',
                'client_email' => 'ista-ai@example.test',
            ]),
            'services.google_drive.root_folder_id' => 'root-folder-id',
            'services.google_drive.default_upload_folder_name' => 'ISTA AI',
        ]);

        $service = app(GoogleDriveService::class);

        $this->assertTrue($service->isConfigured());
        $this->assertSame('root-folder-id', $service->rootFolderId());
        $this->assertSame('ISTA AI', $service->defaultUploadFolderName());
    }

    public function test_service_reports_unconfigured_when_credentials_are_missing(): void
    {
        config([
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = app(GoogleDriveService::class);

        $this->assertFalse($service->isConfigured());
    }

    public function test_empty_shared_drive_id_is_treated_as_null(): void
    {
        config([
            'services.google_drive.shared_drive_id' => '',
        ]);

        $service = app(GoogleDriveService::class);

        $this->assertNull($service->sharedDriveId());
    }

    public function test_empty_impersonated_user_email_is_treated_as_null(): void
    {
        config([
            'services.google_drive.impersonated_user_email' => '',
        ]);

        $service = app(GoogleDriveService::class);

        $this->assertNull($service->impersonatedUserEmail());
    }

    public function test_uploads_require_shared_drive_or_central_oauth_connection(): void
    {
        config([
            'services.google_drive.shared_drive_id' => null,
            'services.google_drive.impersonated_user_email' => null,
            'services.google_drive.root_folder_id' => 'my-drive-folder-id',
        ]);

        $service = app(GoogleDriveService::class);
        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive-upload-test-');

        $this->assertIsString($tempPath);
        file_put_contents($tempPath, 'content');

        try {
            $this->assertFalse($service->canUploadWithConfiguredAccount());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Upload ke Google Drive belum aktif. Hubungkan akun Google Drive pusat lewat OAuth atau gunakan Shared Drive kantor.');

            $service->uploadFromPath($tempPath, 'answer.pdf', 'application/pdf');
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_impersonated_user_email_enables_server_side_uploads(): void
    {
        config([
            'services.google_drive.shared_drive_id' => null,
            'services.google_drive.impersonated_user_email' => 'drive-owner@example.test',
        ]);

        $service = app(GoogleDriveService::class);

        $this->assertSame('drive-owner@example.test', $service->impersonatedUserEmail());
        $this->assertTrue($service->canUploadWithConfiguredAccount());
    }

    public function test_upload_error_message_is_sanitized_for_service_account_quota_errors(): void
    {
        $service = app(GoogleDriveService::class);

        $throwable = new GoogleServiceException(
            '{"error":{"code":403,"message":"Service Accounts do not have storage quota. Leverage shared drives, or use OAuth delegation instead.","errors":[{"message":"Service Accounts do not have storage quota. Leverage shared drives, or use OAuth delegation instead.","domain":"usageLimits","reason":"storageQuotaExceeded"}]}}',
            403,
            null,
            [
                [
                    'message' => 'Service Accounts do not have storage quota. Leverage shared drives, or use OAuth delegation instead.',
                    'domain' => 'usageLimits',
                    'reason' => 'storageQuotaExceeded',
                ],
            ]
        );

        $this->assertSame(
            'Upload ke Google Drive belum aktif. Hubungkan akun Google Drive pusat lewat OAuth atau gunakan Shared Drive kantor.',
            $service->describeUploadFailure($throwable),
        );
    }

    public function test_upload_error_message_extracts_google_api_message_without_raw_json(): void
    {
        $service = app(GoogleDriveService::class);

        $throwable = new \RuntimeException('{"error":{"code":404,"message":"Folder tujuan tidak ditemukan.","errors":[{"message":"Folder tujuan tidak ditemukan.","domain":"global","reason":"notFound"}]}}');

        $this->assertSame('Folder tujuan tidak ditemukan.', $service->describeUploadFailure($throwable));
    }

    public function test_list_files_rejects_folder_outside_configured_root(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = $this->fakeService([
            'outside-folder-id' => $this->fakeDriveFile('outside-folder-id', GoogleDriveServiceFake::FOLDER_MIME, ['outside-parent-id']),
            'outside-parent-id' => $this->fakeDriveFile('outside-parent-id', GoogleDriveServiceFake::FOLDER_MIME, []),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Folder Google Drive berada di luar folder kantor yang diizinkan.');

        $service->listFiles('outside-folder-id');
    }

    public function test_download_to_temp_rejects_file_outside_configured_root(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = $this->fakeService([
            'outside-file-id' => $this->fakeDriveFile('outside-file-id', 'application/pdf', ['outside-folder-id'], 2048),
            'outside-folder-id' => $this->fakeDriveFile('outside-folder-id', GoogleDriveServiceFake::FOLDER_MIME, ['outside-parent-id']),
            'outside-parent-id' => $this->fakeDriveFile('outside-parent-id', GoogleDriveServiceFake::FOLDER_MIME, []),
        ], [
            'outside-file-id' => 'fake pdf contents',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File Google Drive berada di luar folder kantor yang diizinkan.');

        $service->downloadToTemp('outside-file-id');
    }

    public function test_upload_rejects_target_folder_outside_configured_root(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
            'services.google_drive.shared_drive_id' => 'shared-drive-id',
        ]);

        $service = $this->fakeService([
            'outside-folder-id' => $this->fakeDriveFile('outside-folder-id', GoogleDriveServiceFake::FOLDER_MIME, ['outside-parent-id']),
            'outside-parent-id' => $this->fakeDriveFile('outside-parent-id', GoogleDriveServiceFake::FOLDER_MIME, []),
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive-upload-outside-root-');
        $this->assertIsString($tempPath);
        file_put_contents($tempPath, 'content');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Folder Google Drive berada di luar folder kantor yang diizinkan.');

            $service->uploadFromPath($tempPath, 'answer.pdf', 'application/pdf', 'outside-folder-id');
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_download_to_temp_rejects_file_above_limit_before_binary_download_when_metadata_size_exists(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = $this->fakeService([
            'big-file-id' => $this->fakeDriveFile(
                'big-file-id',
                'application/pdf',
                ['root-folder-id'],
                GoogleDriveService::MAX_IMPORT_FILE_SIZE_BYTES + 1,
            ),
        ], [
            'big-file-id' => 'should-not-download',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ukuran file Google Drive melebihi batas 50 MB.');

        try {
            $service->downloadToTemp('big-file-id');
        } finally {
            $this->assertSame(0, $service->downloadRequestCount);
        }
    }

    public function test_download_to_temp_rejects_file_above_limit_when_metadata_size_is_missing(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = $this->fakeService([
            'streamed-file-id' => $this->fakeDriveFile(
                'streamed-file-id',
                'application/pdf',
                ['root-folder-id'],
                null,
            ),
        ], [
            'streamed-file-id' => new FakeDriveBinaryResponse(new FakeDriveBodyStream(
                GoogleDriveService::MAX_IMPORT_FILE_SIZE_BYTES + 1024
            )),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ukuran file Google Drive melebihi batas 50 MB.');

        $service->downloadToTemp('streamed-file-id');
    }

    public function test_list_files_marks_google_drive_shortcuts_as_unsupported(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $shortcut = $this->fakeDriveFile(
            'shortcut-id',
            'application/vnd.google-apps.shortcut',
            ['root-folder-id'],
        );
        $shortcut->setShortcutDetails(new DriveFileShortcutDetails([
            'targetId' => 'target-file-id',
            'targetMimeType' => 'application/pdf',
        ]));

        $service = $this->fakeService([
            'shortcut-id' => $shortcut,
        ]);

        $item = $service->listFiles()['items'][0];

        $this->assertTrue($item['is_shortcut']);
        $this->assertFalse($item['is_processable']);
        $this->assertFalse($item['is_google_workspace_file']);
        $this->assertSame('target-file-id', $item['shortcut_target_id']);
        $this->assertSame('application/pdf', $item['shortcut_target_mime_type']);
        $this->assertStringContainsString('Shortcut Google Drive belum didukung', $item['unsupported_reason']);
    }

    public function test_download_to_temp_rejects_shortcuts_with_specific_message(): void
    {
        config([
            'services.google_drive.root_folder_id' => 'root-folder-id',
        ]);

        $service = $this->fakeService([
            'shortcut-id' => $this->fakeDriveFile(
                'shortcut-id',
                'application/vnd.google-apps.shortcut',
                ['root-folder-id'],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shortcut Google Drive belum didukung.');

        try {
            $service->downloadToTemp('shortcut-id');
        } finally {
            $this->assertSame(0, $service->downloadRequestCount);
        }
    }

    private function fakeService(array $files, array $downloads = []): GoogleDriveServiceFake
    {
        return new GoogleDriveServiceFake($files, $downloads);
    }

    private function fakeDriveFile(string $id, string $mimeType, array $parents = [], ?int $size = null): DriveFile
    {
        return new DriveFile([
            'id' => $id,
            'name' => $id,
            'mimeType' => $mimeType,
            'parents' => $parents,
            'size' => $size,
            'webViewLink' => 'https://drive.google.com/file/d/'.$id.'/view',
        ]);
    }
}

class GoogleDriveServiceFake extends GoogleDriveService
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public int $downloadRequestCount = 0;

    /**
     * @param  array<string, DriveFile>  $files
     * @param  array<string, mixed>  $downloads
     */
    public function __construct(
        private readonly array $files,
        private readonly array $downloads = [],
    ) {}

    protected function listDriveFilesResponse(array $options): mixed
    {
        return new FakeDriveListResponse(array_values($this->files));
    }

    protected function fetchDriveFileRecord(string $fileId, string $fields): DriveFile
    {
        if (! isset($this->files[$fileId])) {
            throw new \RuntimeException('Metadata fake Google Drive tidak ditemukan untuk '.$fileId.'.');
        }

        return $this->files[$fileId];
    }

    protected function downloadDriveBinaryResponse(string $fileId): mixed
    {
        $this->downloadRequestCount++;

        if (! array_key_exists($fileId, $this->downloads)) {
            throw new \RuntimeException('Binary fake Google Drive tidak ditemukan untuk '.$fileId.'.');
        }

        return $this->downloads[$fileId];
    }

    protected function createDriveFileRecord(DriveFile $metadata, array $options): DriveFile
    {
        return new DriveFile([
            'id' => 'created-folder-id',
        ]);
    }
}

class FakeDriveListResponse
{
    /**
     * @param  array<int, DriveFile>  $files
     */
    public function __construct(
        private readonly array $files,
        private readonly ?string $nextPageToken = null,
    ) {}

    /**
     * @return array<int, DriveFile>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getNextPageToken(): ?string
    {
        return $this->nextPageToken;
    }
}

class FakeDriveBinaryResponse
{
    public function __construct(private readonly object $body) {}

    public function getBody(): object
    {
        return $this->body;
    }
}

class FakeDriveBodyStream
{
    public function __construct(private int $remainingBytes) {}

    public function read(int $length): string
    {
        if ($this->remainingBytes <= 0) {
            return '';
        }

        $chunkSize = min($length, $this->remainingBytes);
        $this->remainingBytes -= $chunkSize;

        return str_repeat('A', $chunkSize);
    }
}
