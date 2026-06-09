<?php

namespace Tests\Feature\Documents;

use App\Livewire\Chat\ChatIndex;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_from_chat_cleans_up_storage_and_vector(): void
    {
        Storage::fake('local');
        config()->set('services.ai_document_service.url', 'http://python-ai-docs:8002');
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

        Http::fake([
            '*' => Http::response(['message' => 'success'], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->call('deleteDocument', $document->id);

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($filePath);
        $component->assertSee('Dokumen berhasil dihapus.');
        Http::assertSent(function ($request) use ($document, $user) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/documents/delete_chat.pdf')
                && str_contains($request->url(), 'user_id='.$user->id)
                && str_contains($request->url(), 'document_id='.$document->id);
        });
    }

    public function test_delete_selected_documents_cleans_up_storage_and_vector(): void
    {
        Storage::fake('local');
        config()->set('services.ai_document_service.url', 'http://python-ai-docs:8002');
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

        Http::fake([
            '*' => Http::response(['message' => 'success'], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->set('selectedDocuments', [$doc1->id, $doc2->id])
            ->call('deleteSelectedDocuments');

        $this->assertDatabaseMissing('documents', ['id' => $doc1->id]);
        $this->assertDatabaseMissing('documents', ['id' => $doc2->id]);
        Storage::disk('local')->assertMissing('documents/' . $user->id . '/doc1.pdf');
        Storage::disk('local')->assertMissing('documents/' . $user->id . '/doc2.pdf');
        $component->assertSee('Dokumen terpilih berhasil dihapus.');
        Http::assertSentCount(2);
    }

    public function test_delete_document_uses_explicit_local_disk(): void
    {
        // Verifikasi bahwa deleteDocumentFile menggunakan Storage::disk('local')
        // bukan disk default. Jika FILESYSTEM_DISK diubah, file tetap terhapus.
        Storage::fake('local');
        Storage::fake('s3'); // simulate alternate disk
        config()->set('filesystems.default', 's3');

        Http::fake(['*' => Http::response(['message' => 'success'], 200)]);
        config()->set('services.ai_document_service.url', 'http://python-ai-docs:8002');

        $user = User::factory()->create();
        $filePath = 'documents/'.$user->id.'/explicit-disk.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'explicit-disk.pdf',
            'original_name' => 'explicit-disk.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Chat\ChatIndex::class)
            ->call('deleteDocument', $document->id);

        // File harus terhapus dari disk 'local' meski default disk adalah 's3'
        Storage::disk('local')->assertMissing($filePath);
    }
}
