<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Document;
use App\Models\User;
use App\Services\Chat\ChatDocumentStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChatDocumentStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_available_documents_marks_in_progress_documents_and_returns_ready_ids(): void
    {
        $user = User::factory()->create();
        $readyDocument = $this->createDocument($user, ['status' => 'ready']);
        $errorDocument = $this->createDocument($user, ['status' => 'error']);
        $this->createDocument($user, ['status' => 'processing']);
        $this->createDocument($user, ['status' => 'pending']);

        $service = app(ChatDocumentStateService::class);
        $state = $service->loadAvailableDocuments($user->id);

        $this->assertCount(4, $state['documents']);
        $this->assertTrue($state['has_documents_in_progress']);
        $this->assertSame([$readyDocument->id], $service->readyDocumentIds($user->id));
        $this->assertContains($errorDocument->id, $state['documents']->pluck('id')->all());
    }

    public function test_selection_helpers_keep_only_ready_document_ids(): void
    {
        $service = app(ChatDocumentStateService::class);

        $selectedDocuments = $service->toggleDocument([], 10);
        $this->assertSame([10], $selectedDocuments);

        $selectedDocuments = $service->toggleDocument($selectedDocuments, 12);
        $this->assertSame([10, 12], $selectedDocuments);

        $filteredDocuments = $service->filterSelectedDocuments([10, 12, 99], [10]);
        $this->assertSame([10], $filteredDocuments);

        $this->assertSame([], $service->toggleSelectAllDocuments([10], [10]));
        $this->assertSame([10, 12], $service->toggleSelectAllDocuments([], [10, 12]));
        $this->assertSame([10], $service->addSelectedDocumentsToChat([10, 12], [10]));
        $this->assertSame([10, 12], $service->addDocumentIds([10], [10, 12]));
        $this->assertSame([10], $service->removeDocumentIds([10, 12], 12));
    }

    public function test_load_available_documents_uses_short_lived_cache_until_force_refresh(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $this->createDocument($user, ['status' => 'ready']);

        $service = app(ChatDocumentStateService::class);
        $cached = $service->loadAvailableDocuments($user->id);

        $this->assertCount(1, $cached['documents']);

        $this->createDocument($user, ['status' => 'ready', 'original_name' => 'second.pdf']);

        $stillCached = $service->loadAvailableDocuments($user->id);
        $this->assertCount(1, $stillCached['documents']);

        $fresh = $service->loadAvailableDocuments($user->id, forceRefresh: true);
        $this->assertCount(2, $fresh['documents']);
    }

    public function test_invalidate_available_documents_cache_clears_cached_state(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $service = app(ChatDocumentStateService::class);

        $service->loadAvailableDocuments($user->id);
        $this->createDocument($user, ['status' => 'ready']);

        $service->invalidateAvailableDocumentsCache($user->id);
        $state = $service->loadAvailableDocuments($user->id);

        $this->assertCount(1, $state['documents']);
    }

    private function createDocument(User $user, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'user_id' => $user->id,
            'filename' => uniqid('doc_', true).'.pdf',
            'original_name' => uniqid('file_', true).'.pdf',
            'file_path' => 'documents/'.$user->id.'/'.uniqid('path_', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 120 * 1024,
            'status' => 'ready',
        ], $overrides));
    }
}
