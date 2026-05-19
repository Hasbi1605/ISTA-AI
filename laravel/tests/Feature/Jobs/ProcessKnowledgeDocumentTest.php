<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AIUsageEvent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessKnowledgeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_document_active_on_success_and_records_chunks(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/knowledge/process' => Http::response([
                'status' => 'success',
                'embedding_provider' => 'fake-provider',
                'chunk_count' => 12,
                'successful_chunks' => 12,
                'failed_chunks' => 0,
            ], 200),
        ]);

        config()->set('services.ai_document_service.url', 'http://python-ai:8001');
        config()->set('services.ai_document_service.token', 'internal-token');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeDocument($admin, KnowledgeDocument::STATUS_PROCESSING);
        Storage::disk('local')->put($document->file_path, 'dummy content');

        ProcessKnowledgeDocument::dispatchSync($document);

        $document->refresh();
        $this->assertSame(KnowledgeDocument::STATUS_ACTIVE, $document->status);
        $this->assertNotNull($document->processed_at);

        $chunk = KnowledgeChunk::where('knowledge_document_id', $document->id)->first();
        $this->assertNotNull($chunk);
        $this->assertSame(12, $chunk->chunk_count);
        $this->assertSame('fake-provider', $chunk->embedding_provider);

        Http::assertSent(function ($request) use ($document) {
            return str_contains($request->url(), '/api/knowledge/process')
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer internal-token')
                && $request->isMultipart()
                && collect($request->data())->contains(fn ($field) => $field['name'] === 'document_id' && $field['contents'] === (string) $document->id)
                && collect($request->data())->contains(fn ($field) => $field['name'] === 'scope' && $field['contents'] === KnowledgeDocument::SCOPE_GLOBAL_INTERNAL)
                && collect($request->data())->contains(fn ($field) => $field['name'] === 'audience' && $field['contents'] === KnowledgeDocument::AUDIENCE_ALL_USERS);
        });

        $this->assertDatabaseHas('ai_usage_events', [
            'feature' => AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            'status' => AIUsageEvent::STATUS_SUCCESS,
            'subject_id' => $document->id,
        ]);
    }

    public function test_job_marks_document_error_when_microservice_fails(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/knowledge/process' => Http::response('boom', 500),
        ]);

        config()->set('services.ai_document_service.url', 'http://python-ai:8001');
        config()->set('services.ai_document_service.token', 'internal-token');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeDocument($admin, KnowledgeDocument::STATUS_PROCESSING);
        Storage::disk('local')->put($document->file_path, 'dummy content');

        try {
            ProcessKnowledgeDocument::dispatchSync($document);
        } catch (\Throwable) {
            // re-thrown for retry; ignore in test.
        }

        $document->refresh();
        $this->assertSame(KnowledgeDocument::STATUS_ERROR, $document->status);
        $this->assertContains($document->error_code, ['microservice_error', 'job_failed']);
        $this->assertNotNull($document->failed_at);

        $this->assertDatabaseHas('ai_usage_events', [
            'feature' => AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            'status' => AIUsageEvent::STATUS_ERROR,
            'subject_id' => $document->id,
        ]);
    }

    public function test_job_marks_document_error_when_file_missing(): void
    {
        Storage::fake('local');
        Http::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeDocument($admin, KnowledgeDocument::STATUS_PROCESSING);
        // intentionally do not write the file to storage

        ProcessKnowledgeDocument::dispatchSync($document);

        $document->refresh();
        $this->assertSame(KnowledgeDocument::STATUS_ERROR, $document->status);
        $this->assertSame('file_not_found', $document->error_code);

        Http::assertNothingSent();
    }

    private function makeDocument(User $admin, string $status): KnowledgeDocument
    {
        $source = KnowledgeSource::create([
            'name' => 'SOP Internal',
            'slug' => 'sop-internal-'.uniqid(),
            'created_by_id' => $admin->id,
        ]);

        return KnowledgeDocument::create([
            'knowledge_source_id' => $source->id,
            'uploaded_by_id' => $admin->id,
            'title' => 'SOP Tamu',
            'original_name' => 'sop.pdf',
            'filename' => 'sop.pdf',
            'file_path' => 'knowledge/'.$source->id.'/sop.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'scope' => KnowledgeDocument::SCOPE_GLOBAL_INTERNAL,
            'audience' => KnowledgeDocument::AUDIENCE_ALL_USERS,
            'status' => $status,
            'vector_namespace' => KnowledgeDocument::VECTOR_NAMESPACE,
        ]);
    }
}
