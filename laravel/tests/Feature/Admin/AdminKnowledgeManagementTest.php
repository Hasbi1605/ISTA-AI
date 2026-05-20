<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AIUsageEvent;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminKnowledgeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_knowledge_page_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin/knowledge');

        $response->assertOk();
        $response->assertSee('Knowledge Base Internal', false);
        $response->assertSee('admin-knowledge-kpi-card', false);
        $response->assertSee('Dokumen Knowledge', false);
        $response->assertSee('Upload Knowledge', false);
        $response->assertSee('Admin only', false);
    }

    public function test_upload_modal_backdrop_stays_behind_panel(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            ".admin-knowledge-upload-modal {\n        @apply fixed inset-0 z-50 flex items-center justify-center p-4;\n    }",
            $css
        );
        $this->assertStringContainsString(
            ".admin-knowledge-upload-modal__backdrop {\n        @apply absolute inset-0 bg-stone-950/35 backdrop-blur-sm dark:bg-black/55;\n    }",
            $css
        );
        $this->assertStringNotContainsString(
            ".admin-knowledge-upload-modal,\n    .admin-knowledge-upload-modal__backdrop {\n        @apply fixed inset-0 z-50;\n    }",
            $css
        );
    }

    public function test_regular_user_cannot_access_knowledge_page(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->get('/admin/knowledge')
            ->assertRedirect(route('admin.login'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/knowledge')->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_upload_knowledge_document(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->create('sop.pdf', 12, 'application/pdf');

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->set('title', 'SOP Penerimaan Tamu')
            ->set('newSourceName', 'SOP Internal')
            ->set('file', $file)
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('knowledge_sources', [
            'name' => 'SOP Internal',
            'created_by_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('knowledge_documents', [
            'title' => 'SOP Penerimaan Tamu',
            'original_name' => 'sop.pdf',
            'mime_type' => 'application/pdf',
            'scope' => KnowledgeDocument::SCOPE_GLOBAL_INTERNAL,
            'audience' => KnowledgeDocument::AUDIENCE_ALL_USERS,
            'status' => KnowledgeDocument::STATUS_PROCESSING,
        ]);

        Queue::assertPushed(ProcessKnowledgeDocument::class);

        $this->assertDatabaseHas('ai_usage_events', [
            'feature' => AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN,
            'status' => AIUsageEvent::STATUS_SUCCESS,
            'user_id' => $admin->id,
        ]);
    }

    public function test_upload_rejects_invalid_mime_type(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->create('malware.exe', 5, 'application/x-msdownload');

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->set('file', $file)
            ->call('upload')
            ->assertHasErrors(['file']);

        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_rejects_plain_text_even_when_mime_is_allowed_for_csv(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->create('notes.txt', 5, 'text/plain');

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->set('file', $file)
            ->call('upload')
            ->assertHasErrors(['file']);

        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_archive_and_activate_knowledge(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeKnowledgeDocument(['status' => KnowledgeDocument::STATUS_ACTIVE]);

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->call('archive', $document->id);

        $this->assertSame(KnowledgeDocument::STATUS_ARCHIVED, $document->fresh()->status);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->call('activate', $document->id);

        $this->assertSame(KnowledgeDocument::STATUS_ACTIVE, $document->fresh()->status);

        $this->assertGreaterThanOrEqual(2, AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN)
            ->count());
    }

    public function test_admin_can_reprocess_knowledge_document(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeKnowledgeDocument(['status' => KnowledgeDocument::STATUS_ERROR]);

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->call('reprocess', $document->id);

        Queue::assertPushed(ProcessKnowledgeDocument::class);
        $this->assertSame(KnowledgeDocument::STATUS_PROCESSING, $document->fresh()->status);
    }

    public function test_admin_can_delete_knowledge_document_and_cleanup_vectors(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake([
            '*/api/knowledge/*' => Http::response([], 200),
        ]);

        config()->set('services.ai_document_service.url', 'http://python-ai:8001');
        config()->set('services.ai_document_service.token', 'internal-token');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeKnowledgeDocument([
            'status' => KnowledgeDocument::STATUS_ACTIVE,
            'file_path' => 'knowledge/1/sop.pdf',
        ]);
        Storage::disk('local')->put($document->file_path, 'dummy');

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->call('delete', $document->id);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        $this->assertFalse(Storage::disk('local')->exists('knowledge/1/sop.pdf'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/knowledge/sop.pdf')
                && $request->method() === 'DELETE'
                && str_contains($request->url(), 'cleanup_legacy=true');
        });
    }

    public function test_filter_by_status_returns_only_matching_rows(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->makeKnowledgeDocument([
            'title' => 'SOP Active Doc',
            'original_name' => 'active.pdf',
            'status' => KnowledgeDocument::STATUS_ACTIVE,
        ]);
        $this->makeKnowledgeDocument([
            'title' => 'SOP Archived Doc',
            'original_name' => 'archived.pdf',
            'status' => KnowledgeDocument::STATUS_ARCHIVED,
        ]);

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Admin\AdminKnowledge::class)
            ->set('status', KnowledgeDocument::STATUS_ACTIVE)
            ->assertSee('SOP Active Doc')
            ->assertDontSee('SOP Archived Doc');
    }

    private function makeKnowledgeDocument(array $overrides = []): KnowledgeDocument
    {
        $source = KnowledgeSource::create([
            'name' => $overrides['source_name'] ?? 'SOP Internal',
            'slug' => 'sop-internal-'.uniqid(),
        ]);

        return KnowledgeDocument::create(array_merge([
            'knowledge_source_id' => $source->id,
            'uploaded_by_id' => null,
            'title' => 'Default Knowledge Title',
            'original_name' => 'sop.pdf',
            'filename' => 'sop.pdf',
            'file_path' => 'knowledge/1/sop.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'scope' => KnowledgeDocument::SCOPE_GLOBAL_INTERNAL,
            'audience' => KnowledgeDocument::AUDIENCE_ALL_USERS,
            'status' => KnowledgeDocument::STATUS_DRAFT,
            'vector_namespace' => KnowledgeDocument::VECTOR_NAMESPACE,
        ], $overrides));
    }
}
