<?php

namespace Tests\Unit\Services;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\AIService;
use App\Services\DocumentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_document_continues_when_preview_dispatch_fails(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $service = $this->partialMock(DocumentLifecycleService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatchPreviewRendering')
                ->once()
                ->andThrow(new \RuntimeException('queue down'));
        });

        $document = $service->uploadDocument(
            UploadedFile::fake()->create('referensi.pdf', 120, 'application/pdf'),
            $user->id,
        );

        $this->assertDatabaseCount('documents', 1);
        $this->assertSame('referensi.pdf', $document->original_name);
        $this->assertSame('pending', $document->status);

        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_upload_document_accepts_csv_for_conversion_workflows(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $service = app(DocumentLifecycleService::class);
        $document = $service->uploadDocument(
            UploadedFile::fake()->create('biaya.csv', 8, 'text/csv'),
            $user->id,
        );

        $this->assertSame('biaya.csv', $document->original_name);
        $this->assertSame('text/csv', $document->mime_type);
        $this->assertSame('pending', $document->status);

        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_upload_document_rejects_plain_text_txt_before_queueing(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        try {
            app(DocumentLifecycleService::class)->uploadDocument(
                UploadedFile::fake()->create('notes.txt', 8, 'text/plain'),
                $user->id,
            );
        } finally {
            $this->assertDatabaseCount('documents', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_summarize_document_rejects_non_ready_documents(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'draft.pdf',
            'original_name' => 'draft.pdf',
            'file_path' => 'documents/'.$user->id.'/draft.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 12,
            'status' => 'processing',
        ]);

        $aiService = $this->createMock(AIService::class);
        $aiService->expects($this->never())->method('summarizeDocument');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dokumen belum selesai diproses. Tunggu hingga status menjadi "ready".');

        app(DocumentLifecycleService::class)->summarizeDocument($document, $aiService);
    }

    public function test_summarize_document_delegates_to_ai_service_for_ready_documents(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'ringkasan.pdf',
            'original_name' => 'ringkasan.pdf',
            'file_path' => 'documents/'.$user->id.'/ringkasan.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 12,
            'status' => 'ready',
        ]);

        $aiService = $this->createMock(AIService::class);
        $aiService->expects($this->once())
            ->method('summarizeDocument')
            ->with('ringkasan.pdf', (string) $user->id)
            ->willReturn(['status' => 'success', 'summary' => 'Ringkasan final']);

        $result = app(DocumentLifecycleService::class)->summarizeDocument($document, $aiService);

        $this->assertSame(['status' => 'success', 'summary' => 'Ringkasan final'], $result);
    }
}
