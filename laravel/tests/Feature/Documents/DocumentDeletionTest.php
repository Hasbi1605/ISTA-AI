<?php

namespace Tests\Feature\Documents;

use App\Livewire\Chat\ChatIndex;
use App\Livewire\Documents\DocumentIndex;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai_runtime.document_delete', 'python');
    }

    public function test_delete_document_passes_user_id_to_runtime(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/delete_user.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'delete_user.pdf',
            'original_name' => 'delete_user.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(DocumentIndex::class)
            ->call('delete', $document->id);

        $this->assertSoftDeleted($document);
    }

    public function test_delete_from_document_index_cleans_up_storage_and_vector(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/delete.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'delete.pdf',
            'original_name' => 'delete.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(DocumentIndex::class)
            ->call('delete', $document->id);

        $this->assertSoftDeleted($document);
        Storage::disk('local')->assertMissing($filePath);
    }

    public function test_delete_from_chat_cleans_up_storage_and_vector(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/delete_chat.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'delete_chat.pdf',
            'original_name' => 'delete_chat.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('deleteDocument', $document->id);

        $this->assertSoftDeleted($document);
        Storage::disk('local')->assertMissing($filePath);
    }

    public function test_delete_selected_documents_cleans_up_storage_and_vector(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $doc1 = Document::create([
            'user_id' => $user->id,
            'filename' => 'doc1.pdf',
            'original_name' => 'doc1.pdf',
            'file_path' => 'documents/' . $user->id . '/doc1.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);
        Storage::disk('local')->put('documents/' . $user->id . '/doc1.pdf', 'dummy content');

        $doc2 = Document::create([
            'user_id' => $user->id,
            'filename' => 'doc2.pdf',
            'original_name' => 'doc2.pdf',
            'file_path' => 'documents/' . $user->id . '/doc2.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);
        Storage::disk('local')->put('documents/' . $user->id . '/doc2.pdf', 'dummy content');

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('selectedDocuments', [$doc1->id, $doc2->id])
            ->call('deleteSelectedDocuments');

        $this->assertSoftDeleted($doc1);
        $this->assertSoftDeleted($doc2);
        Storage::disk('local')->assertMissing('documents/' . $user->id . '/doc1.pdf');
        Storage::disk('local')->assertMissing('documents/' . $user->id . '/doc2.pdf');
    }
}
