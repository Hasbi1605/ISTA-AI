<?php

namespace Tests\Feature\Documents;

use App\Jobs\ProcessDocument;
use App\Livewire\Documents\DocumentIndex;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_document_via_document_index(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DocumentIndex::class)
            ->set('file', UploadedFile::fake()->create('dokumen.pdf', 120, 'application/pdf'))
            ->call('saveDocument')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('documents', 1);

        $document = Document::first();
        $this->assertSame('dokumen.pdf', $document->original_name);
        $this->assertSame('pending', $document->status);

        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_summarize_rejects_non_ready_document(): void
    {
        $user = User::factory()->create();

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'pending.pdf',
            'original_name' => 'pending.pdf',
            'file_path' => 'documents/' . $user->id . '/pending.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 120,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test(DocumentIndex::class)
            ->call('summarize', $document->id)
            ->assertSet('showSummaryModal', false);
    }
}
