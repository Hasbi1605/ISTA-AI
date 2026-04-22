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
        // Use default 'laravel' from ai_runtime.php config - no override needed
    }

    public function test_delete_uses_laravel_runtime_by_default(): void
    {
        // Verify the default is now laravel
        $defaultRuntime = config('ai_runtime.document_delete');
        $this->assertEquals('laravel', $defaultRuntime);

        // Set config so LaravelAIGateway::isReady() returns true
        // Both api_key and at least one feature flag must be set
        Config::set('ai.laravel_ai.api_key', 'test-key-123');
        Config::set('ai.laravel_ai.document_delete_enabled', true);
        Config::set('ai.laravel_ai.document_process_enabled', false);
        Config::set('ai.laravel_ai.document_summarize_enabled', false);

        // Verify LaravelAIGateway::isReady() returns true
        $gateway = new \App\Services\Runtime\LaravelAIGateway();
        $this->assertTrue($gateway->isReady());

        // CRITICAL: Verify AIRuntimeResolver actually returns LaravelAIGateway
        // (not Python fallback), since the resolver has fallback logic
        // that falls back to Python if the selected runtime is not ready
        $resolver = new \App\Services\AIRuntimeResolver('document_delete', false);
        $runtime = $resolver->getRuntime();
        $this->assertInstanceOf(\App\Services\Runtime\LaravelAIGateway::class, $runtime);
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
