<?php

namespace Tests\Feature\Chat;

use App\Jobs\ProcessDocument;
use App\Livewire\Chat\ChatIndex;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_document_via_chat(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('chatAttachment', UploadedFile::fake()->create('referensi.pdf', 120, 'application/pdf'))
            ->assertSet('attachmentUploadStatus', 'success');

        $this->assertDatabaseCount('documents', 1);

        $document = Document::first();
        $this->assertSame('referensi.pdf', $document->original_name);
        $this->assertNotNull($document->mime_type);
        $this->assertNotNull($document->file_size_bytes);
        $this->assertSame('pending', $document->status);

        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_user_cannot_upload_more_than_10_documents(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->createDocument($user, [
                'original_name' => "existing-{$i}.pdf",
                'status' => 'ready',
            ]);
        }

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('chatAttachment', UploadedFile::fake()->create('limit-test.pdf', 120, 'application/pdf'))
            ->assertSet('attachmentUploadStatus', 'error');

        $this->assertDatabaseCount('documents', 10);
        Queue::assertNothingPushed();
    }

    public function test_invalid_file_type_is_rejected_in_chat_upload(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('chatAttachment', UploadedFile::fake()->create('invalid.txt', 20, 'text/plain'))
            ->assertSet('attachmentUploadStatus', 'error');

        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_filename_is_rejected_in_chat_upload(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->createDocument($user, [
            'original_name' => 'duplikat.pdf',
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('chatAttachment', UploadedFile::fake()->create('duplikat.pdf', 120, 'application/pdf'))
            ->assertSet('attachmentUploadStatus', 'error');

        $this->assertDatabaseCount('documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_select_all_documents_only_selects_ready_documents(): void
    {
        $user = User::factory()->create();

        $readyDoc = $this->createDocument($user, [
            'original_name' => 'ready.pdf',
            'status' => 'ready',
        ]);

        $this->createDocument($user, [
            'original_name' => 'pending.pdf',
            'status' => 'pending',
        ]);

        $this->createDocument($user, [
            'original_name' => 'processing.pdf',
            'status' => 'processing',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('selectAllDocuments');

        $selectedDocuments = array_map('intval', $component->get('selectedDocuments'));

        $this->assertSame([$readyDoc->id], $selectedDocuments);
    }

    private function createDocument(User $user, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'user_id' => $user->id,
            'filename' => uniqid('doc_', true) . '.pdf',
            'original_name' => uniqid('file_', true) . '.pdf',
            'file_path' => 'documents/' . $user->id . '/' . uniqid('path_', true) . '.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 120 * 1024,
            'status' => 'ready',
        ], $overrides));
    }
}
