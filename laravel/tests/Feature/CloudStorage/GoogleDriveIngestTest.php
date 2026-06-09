<?php

namespace Tests\Feature\CloudStorage;

use App\Jobs\ProcessDocument;
use App\Jobs\RenderDocumentPreview;
use App\Models\Document;
use App\Models\User;
use App\Services\CloudStorage\GoogleDriveService;
use App\Services\DocumentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class GoogleDriveIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_drive_file_is_ingested_into_document_pipeline(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive_');

        if ($tempPath === false) {
            $this->fail('Gagal membuat file sementara untuk test.');
        }

        file_put_contents($tempPath, '%PDF-1.4 fake');

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('downloadToTemp')
            ->once()
            ->with('drive-file-id')
            ->andReturn([
                'external_id' => 'drive-file-id',
                'original_name' => 'surat-drive.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1234,
                'web_view_link' => 'https://drive.google.com/file/d/drive-file-id/view',
                'folder_external_id' => 'folder-drive-id',
                'path' => $tempPath,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        $document = app(DocumentLifecycleService::class)->ingestFromCloud($user, 'google_drive', 'drive-file-id');

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'user_id' => $user->id,
            'original_name' => 'surat-drive.pdf',
            'source_provider' => 'google_drive',
            'source_external_id' => 'drive-file-id',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'direction' => 'import',
            'local_type' => Document::class,
            'local_id' => $document->id,
            'external_id' => 'drive-file-id',
            'name' => 'surat-drive.pdf',
            'mime_type' => 'application/pdf',
            'folder_external_id' => 'folder-drive-id',
        ]);

        $this->assertFalse(is_file($tempPath));

        Queue::assertPushed(ProcessDocument::class, 1);
        Queue::assertPushed(RenderDocumentPreview::class, 1);
    }

    public function test_google_drive_ingest_rejects_files_above_fifty_megabytes(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('downloadToTemp')
            ->once()
            ->with('drive-file-id')
            ->andThrow(new \RuntimeException('Ukuran file Google Drive melebihi batas 50 MB.'));

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ukuran file Google Drive melebihi batas 50 MB.');

        try {
            app(DocumentLifecycleService::class)->ingestFromCloud($user, 'google_drive', 'drive-file-id');
        } finally {
            $this->assertDatabaseCount('documents', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_google_drive_ingest_rejects_plain_text_txt_before_queueing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive_');

        if ($tempPath === false) {
            $this->fail('Gagal membuat file sementara untuk test.');
        }

        file_put_contents($tempPath, 'catatan biasa');

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('downloadToTemp')
            ->once()
            ->with('drive-file-id')
            ->andReturn([
                'external_id' => 'drive-file-id',
                'original_name' => 'notes.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 64,
                'web_view_link' => 'https://drive.google.com/file/d/drive-file-id/view',
                'folder_external_id' => 'folder-drive-id',
                'path' => $tempPath,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        try {
            app(DocumentLifecycleService::class)->ingestFromCloud($user, 'google_drive', 'drive-file-id');
            $this->fail('Google Drive TXT import seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Format file dari Google Drive tidak didukung', $exception->getMessage());
        } finally {
            $this->assertDatabaseCount('documents', 0);
            $this->assertFalse(is_file($tempPath));
            Queue::assertNothingPushed();
        }
    }
}
