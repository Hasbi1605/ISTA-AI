<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessKnowledgeDocument;
use App\Livewire\Admin\AdminKnowledge;
use App\Models\AIUsageEvent;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\User;
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

    public function test_admin_knowledge_layout_focuses_on_full_width_documents_table(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin/knowledge');

        $response->assertOk();
        $response->assertSee('admin-knowledge-main-stack admin-knowledge-main-stack--full', false);
        $response->assertSee('admin-knowledge-table-panel__actions', false);
        $response->assertSee('Upload Knowledge', false);
        $response->assertDontSee('Status pipeline', false);
        $response->assertDontSee('Urutan proses dokumen knowledge', false);

        $blade = file_get_contents(resource_path('views/livewire/admin/admin-knowledge.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('admin-knowledge-content-grid', $blade);
        $this->assertStringNotContainsString('admin-knowledge-side-grid', $blade);
        $this->assertStringNotContainsString('admin-knowledge-status-panel', $blade);
        $this->assertStringNotContainsString('admin-knowledge-pipeline-steps', $blade);
        $this->assertStringContainsString('.admin-knowledge-main-stack--full', $css);
        $this->assertStringContainsString('.admin-knowledge-table-panel__actions', $css);
        $this->assertStringNotContainsString('.admin-knowledge-content-grid--wide', $css);
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

    public function test_upload_modal_exposes_clear_loading_and_processing_feedback(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->call('openUploadModal')
            ->assertSee('Mengirim file knowledge')
            ->assertSee('Menjadwalkan processing')
            ->assertSee('Dokumen akan muncul sebagai Processing')
            ->assertSee('Pilih source dan file untuk mengaktifkan tombol upload')
            ->assertSee('Processing...');

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.admin-knowledge-upload-progress', $css);
        $this->assertStringContainsString('.admin-knowledge-upload-button__spinner', $css);
    }

    public function test_upload_modal_submit_uses_explicit_action_and_client_lock(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/admin/admin-knowledge.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('x-on:submit.prevent="submitKnowledgeUpload()"', $blade);
        $this->assertStringContainsString("Promise.resolve(\$wire.\$call('submitKnowledgeUpload'))", $blade);
        $this->assertStringNotContainsString('data-upload-can-submit', $blade);
        $this->assertStringNotContainsString('canSubmit()', $blade);
        $this->assertStringNotContainsString('x-bind:disabled="isBusy || ! canSubmit()"', $blade);
        $this->assertStringContainsString('x-bind:disabled="isBusy"', $blade);
        $this->assertStringContainsString('wire:target="submitKnowledgeUpload,file"', $blade);
        $this->assertStringContainsString("x-bind:class=\"{ 'admin-knowledge-dropzone--disabled': isBusy }\"", $blade);
        $this->assertStringContainsString('.admin-knowledge-upload-progress--visible', $css);
    }

    public function test_knowledge_pipeline_polls_while_documents_are_pending(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->makeKnowledgeDocument([
            'title' => 'Pending Pipeline Knowledge',
            'status' => KnowledgeDocument::STATUS_PROCESSING,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->assertSee('wire:poll.5s="refreshKnowledgePipeline"', false)
            ->assertSee('admin-knowledge-pipeline-sync', false)
            ->assertSee('1 dokumen sedang diproses')
            ->assertSee('Status pipeline akan tersinkron otomatis.');
    }

    public function test_knowledge_pipeline_polling_stops_when_no_documents_are_pending(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->makeKnowledgeDocument([
            'title' => 'Ready Pipeline Knowledge',
            'status' => KnowledgeDocument::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->assertDontSee('wire:poll.5s="refreshKnowledgePipeline"', false)
            ->assertDontSee('admin-knowledge-pipeline-sync', false)
            ->assertDontSee('Status pipeline akan tersinkron otomatis.');
    }

    public function test_knowledge_pipeline_polling_pauses_while_upload_modal_is_open(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->makeKnowledgeDocument([
            'title' => 'Pending Pipeline Knowledge',
            'status' => KnowledgeDocument::STATUS_PROCESSING,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->call('openUploadModal')
            ->assertDontSee('wire:poll.5s="refreshKnowledgePipeline"', false)
            ->assertSee('admin-knowledge-pipeline-sync', false);
    }

    public function test_upload_modal_cannot_close_while_uploading(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->call('openUploadModal')
            ->set('isUploading', true)
            ->call('closeUploadModal')
            ->assertSet('showUploadModal', true);
    }

    public function test_upload_requires_file_and_source_before_processing(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->call('openUploadModal')
            ->call('upload')
            ->assertHasErrors(['file', 'newSourceName'])
            ->assertSet('isUploading', false)
            ->assertSet('showUploadModal', true)
            ->assertSee('Upload knowledge belum bisa diproses')
            ->assertSee('Pilih file knowledge terlebih dahulu')
            ->assertSee('Pilih source existing atau isi source baru');

        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
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

        Livewire::test(AdminKnowledge::class)
            ->set('search', 'filter yang disiapkan sebelum upload')
            ->set('status', KnowledgeDocument::STATUS_ACTIVE)
            ->set('title', 'SOP Penerimaan Tamu')
            ->set('newSourceName', 'SOP Internal')
            ->set('file', $file)
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSet('sourceFilter', '')
            ->assertSet('isUploading', false)
            ->assertSee('SOP Penerimaan Tamu')
            ->assertSee('admin-knowledge-table__row--recent', false);

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

        Livewire::test(AdminKnowledge::class)
            ->set('newSourceName', 'SOP Internal')
            ->set('file', $file)
            ->call('upload')
            ->assertHasErrors(['file']);

        $this->assertDatabaseCount('knowledge_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_keeps_modal_open_and_shows_visible_file_error_when_mime_is_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->create('sop.pdf', 12, 'application/octet-stream');

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->call('openUploadModal')
            ->set('newSourceName', 'SOP Internal')
            ->set('file', $file)
            ->call('upload')
            ->assertHasErrors(['file'])
            ->assertSet('isUploading', false)
            ->assertSet('showUploadModal', true)
            ->assertSee('Upload knowledge belum bisa diproses')
            ->assertSee('Tipe file tidak didukung')
            ->assertSee('sop.pdf');

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

        Livewire::test(AdminKnowledge::class)
            ->set('newSourceName', 'SOP Internal')
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

        Livewire::test(AdminKnowledge::class)
            ->call('archive', $document->id);

        $this->assertSame(KnowledgeDocument::STATUS_ARCHIVED, $document->fresh()->status);

        Livewire::test(AdminKnowledge::class)
            ->call('activate', $document->id);

        $this->assertSame(KnowledgeDocument::STATUS_ACTIVE, $document->fresh()->status);

        $this->assertGreaterThanOrEqual(2, AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN)
            ->count());
    }

    public function test_knowledge_action_buttons_are_compact_icon_only_and_state_aware(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->makeKnowledgeDocument([
            'title' => 'Active Knowledge',
            'status' => KnowledgeDocument::STATUS_ACTIVE,
        ]);
        $processing = $this->makeKnowledgeDocument([
            'title' => 'Processing Knowledge',
            'status' => KnowledgeDocument::STATUS_PROCESSING,
        ]);
        $this->makeKnowledgeDocument([
            'title' => 'Archived Knowledge',
            'status' => KnowledgeDocument::STATUS_ARCHIVED,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
            ->assertSee('aria-label="Arsip Active Knowledge"', false)
            ->assertSee('aria-label="Proses ulang Active Knowledge"', false)
            ->assertSee('aria-label="Hapus Processing Knowledge"', false)
            ->assertDontSee('aria-label="Aktifkan Processing Knowledge"', false)
            ->assertDontSee('aria-label="Arsip Processing Knowledge"', false)
            ->assertDontSee('aria-label="Proses ulang Processing Knowledge"', false)
            ->assertSee('aria-label="Aktifkan Archived Knowledge"', false)
            ->call('activate', $processing->id);

        $this->assertSame(KnowledgeDocument::STATUS_PROCESSING, $processing->fresh()->status);

        $blade = file_get_contents(resource_path('views/livewire/admin/admin-knowledge.blade.php'));

        $this->assertStringNotContainsString('>Aktifkan</button>', $blade);
        $this->assertStringNotContainsString('>Arsip</button>', $blade);
        $this->assertStringNotContainsString('>Proses ulang</button>', $blade);
        $this->assertStringNotContainsString('>Hapus</button>', $blade);
    }

    public function test_admin_can_reprocess_knowledge_document(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $document = $this->makeKnowledgeDocument(['status' => KnowledgeDocument::STATUS_ERROR]);

        $this->actingAs($admin);

        Livewire::test(AdminKnowledge::class)
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

        Livewire::test(AdminKnowledge::class)
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

        Livewire::test(AdminKnowledge::class)
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
