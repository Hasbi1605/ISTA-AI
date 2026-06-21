<?php

namespace Tests\Feature\Presentations;

use App\Jobs\GeneratePresentation;
use App\Livewire\Presentations\PresentationWorkspace;
use App\Models\Document;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PresentationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function fakePptxBytes(): string
    {
        return 'PK'.str_repeat("\x00pptx-content", 30);
    }

    protected function tearDown(): void
    {
        RateLimiter::clearResolvedInstances();
        parent::tearDown();
    }

    private function makeDocument(User $user, string $status = 'ready'): Document
    {
        $slug = $status.'-'.uniqid();

        return Document::create([
            'user_id' => $user->id,
            'filename' => $slug.'.pdf',
            'original_name' => $slug.'.pdf',
            'file_path' => 'documents/'.$user->id.'/'.$slug.'.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => $status,
        ]);
    }

    private function makeReadyPresentationWithFile(User $user): Presentation
    {
        $path = 'presentations/'.$user->id.'/workspace-ready.pptx';
        Storage::disk('local')->put($path, 'pptx-bytes');

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Deck Siap',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
            'pptx_path' => $path,
            'generated_at' => now(),
        ]);

        $version = $presentation->versions()->create([
            'version_number' => 1,
            'label' => 'Versi 1',
            'pptx_path' => $path,
            'status' => Presentation::STATUS_READY,
        ]);

        $presentation->forceFill(['current_version_id' => $version->id])->save();

        return $presentation->refresh();
    }

    public function test_workspace_renders_configuration_form_and_submodes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSee('Konfigurasi Presentasi')
            ->assertSee('Buat PPT ISTA')
            ->assertSee('Prompy Studio')
            ->assertSee('Resmi Klasik')
            ->assertSee('Modern Minimal')
            ->assertSee('Presentasi Baru')
            ->assertSee('Panel Presentasi')
            ->assertSee('Belum ada presentasi aktif')
            ->assertSee('aria-label="Toggle dark mode"', false)
            ->assertSee('x-data="presentationWorkspace"', false);
    }

    public function test_prompy_submode_embeds_prompt_studio(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('setSubMode', 'prompy')
            ->assertSee('Prompy Studio')
            ->assertSee('Ide / permintaan')
            ->assertSee('Buat Paket Prompt')
            ->assertSee('Paket Prompt')
            ->assertSee('Prompt Baru')
            ->assertSee('Belum ada prompt')
            ->assertSee('Belum ada paket prompt')
            ->assertDontSee('Presentasi Baru')
            ->assertDontSee('Segera hadir');
    }

    public function test_generate_creates_pending_presentation_and_dispatches_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->set('title', 'Paparan Triwulan')
            ->set('visualTemplate', 'executive_brief')
            ->set('slideCount', 6)
            ->set('additionalInstruction', 'Tonjolkan capaian')
            ->call('generate');

        $this->assertDatabaseHas('presentations', [
            'user_id' => $user->id,
            'title' => 'Paparan Triwulan',
            'visual_template' => 'executive_brief',
            'status' => Presentation::STATUS_PENDING,
        ]);
        Queue::assertPushed(GeneratePresentation::class);
    }

    public function test_generate_inline_finishes_and_opens_ready_presentation(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake([
            '*/api/presentations/generate' => Http::response($this->fakePptxBytes(), 200, [
                'X-Presentation-Template' => 'executive_brief',
                'X-Presentation-Slide-Count' => '6',
            ]),
        ]);
        config([
            'presentations.generation.mode' => 'inline',
            'services.onlyoffice.jwt_secret' => 'workspace-secret',
            'services.onlyoffice.public_url' => 'https://onlyoffice.test',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->set('title', 'Paparan Triwulan')
            ->set('visualTemplate', 'executive_brief')
            ->set('slideCount', 6)
            ->call('generate')
            ->assertSee('Presentasi selesai dibuat.');

        $presentation = Presentation::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(Presentation::STATUS_READY, $presentation->status);
        $component
            ->assertSet('activePresentationId', $presentation->id)
            ->assertSet('editingPresentationId', $presentation->id);
        Queue::assertNotPushed(GeneratePresentation::class);
    }

    public function test_generate_requires_title(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->set('title', '')
            ->call('generate')
            ->assertHasErrors(['title' => 'required']);

        Queue::assertNotPushed(GeneratePresentation::class);
    }

    public function test_toggle_document_only_accepts_owned_ready_documents(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ownedReady = $this->makeDocument($user, 'ready');
        $ownedProcessing = $this->makeDocument($user, 'processing');
        $foreign = $this->makeDocument($other, 'ready');

        $component = Livewire::actingAs($user)->test(PresentationWorkspace::class);

        $component->call('toggleDocument', $ownedReady->id)->assertSet('selectedDocuments', [$ownedReady->id]);
        $component->call('toggleDocument', $ownedProcessing->id)->assertSet('selectedDocuments', [$ownedReady->id]);
        $component->call('toggleDocument', $foreign->id)->assertSet('selectedDocuments', [$ownedReady->id]);
        // Toggle off
        $component->call('toggleDocument', $ownedReady->id)->assertSet('selectedDocuments', []);
    }

    public function test_history_only_shows_own_presentations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Presentation::create([
            'user_id' => $user->id,
            'title' => 'Punya Saya',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
        ]);
        Presentation::create([
            'user_id' => $other->id,
            'title' => 'Punya Orang Lain',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSee('Punya Saya')
            ->assertDontSee('Punya Orang Lain');
    }

    public function test_ready_presentation_opens_in_right_editor_panel_by_default(): void
    {
        Storage::fake('local');
        config([
            'services.onlyoffice.jwt_secret' => 'workspace-secret',
            'services.onlyoffice.public_url' => 'https://onlyoffice.test',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->makeReadyPresentationWithFile($user);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSet('activePresentationId', $presentation->id)
            ->assertSee('Slides')
            ->assertSee('presentation-workspace-editor-', false)
            ->assertSee('documentType', false)
            ->assertSee('slide', false);
    }

    public function test_stale_pending_presentation_shows_recovery_handler_and_can_be_redispatched(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render Mandek',
            'status' => Presentation::STATUS_PENDING,
            'visual_template' => 'resmi_klasik',
        ]);
        $presentation->forceFill(['updated_at' => now()->subMinutes(15)])->save();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSee('Render belum selesai')
            ->assertSee('Kirim ulang')
            ->assertDontSee('Terlalu lama menunggu')
            ->assertDontSee('Menunggu')
            ->call('retry', $presentation->id)
            ->assertSee('Presentasi dikirim ulang karena proses sebelumnya terlalu lama.');

        $this->assertSame(Presentation::STATUS_PENDING, $presentation->refresh()->status);
        Queue::assertPushed(GeneratePresentation::class);
    }

    public function test_fresh_processing_presentation_shows_loading_without_stale_recovery(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Masih Diproses',
            'status' => Presentation::STATUS_PROCESSING,
            'visual_template' => 'resmi_klasik',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSee('Merender presentasi')
            ->assertDontSee('Render belum selesai')
            ->assertDontSee('Menunggu')
            ->call('retry', $presentation->id)
            ->assertSee('Presentasi masih diproses.');

        Queue::assertNotPushed(GeneratePresentation::class);
    }

    public function test_retry_redispatches_job_for_errored_presentation(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Gagal Sebelumnya',
            'status' => Presentation::STATUS_ERROR,
            'visual_template' => 'resmi_klasik',
            'error_message' => 'Gagal.',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('retry', $presentation->id);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_PENDING, $presentation->status);
        $this->assertNull($presentation->error_message);
        Queue::assertPushed(GeneratePresentation::class);
    }

    public function test_retry_inline_finishes_errored_presentation_and_opens_editor(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake([
            '*/api/presentations/generate' => Http::response($this->fakePptxBytes(), 200, [
                'X-Presentation-Template' => 'resmi_klasik',
                'X-Presentation-Slide-Count' => '8',
            ]),
        ]);
        config([
            'presentations.generation.mode' => 'inline',
            'services.onlyoffice.jwt_secret' => 'workspace-secret',
            'services.onlyoffice.public_url' => 'https://onlyoffice.test',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Gagal Sebelumnya',
            'status' => Presentation::STATUS_ERROR,
            'visual_template' => 'resmi_klasik',
            'configuration' => ['title' => 'Gagal Sebelumnya', 'visual_template' => 'resmi_klasik', 'slide_count' => 8],
            'outline' => [['title' => 'Agenda', 'bullets' => ['A', 'B']]],
            'source_document_ids' => [],
            'error_message' => 'Gagal.',
        ]);

        $component = Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('retry', $presentation->id)
            ->assertSee('Presentasi selesai dibuat.');

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_READY, $presentation->status);
        $this->assertNull($presentation->error_message);
        $component
            ->assertSet('activePresentationId', $presentation->id)
            ->assertSet('editingPresentationId', $presentation->id);
        Queue::assertNotPushed(GeneratePresentation::class);
    }

    public function test_retry_inline_failure_keeps_error_state_and_shows_safe_message(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake([
            '*/api/presentations/generate' => Http::response('RuntimeException: renderer down', 500),
        ]);
        config(['presentations.generation.mode' => 'inline']);

        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Gagal Sebelumnya',
            'status' => Presentation::STATUS_ERROR,
            'visual_template' => 'resmi_klasik',
            'configuration' => ['title' => 'Gagal Sebelumnya', 'visual_template' => 'resmi_klasik', 'slide_count' => 8],
            'outline' => [['title' => 'Agenda', 'bullets' => ['A', 'B']]],
            'source_document_ids' => [],
            'error_message' => 'Gagal.',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('retry', $presentation->id)
            ->assertSee('Gagal membuat ulang presentasi. Coba lagi sebentar.');

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        $this->assertSame('Gagal membuat presentasi. Silakan coba generate ulang.', $presentation->error_message);
        Queue::assertNotPushed(GeneratePresentation::class);
    }

    public function test_retry_inline_non_throwing_error_shows_job_error_message(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake();
        config(['presentations.generation.mode' => 'inline']);

        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Dokumen Tidak Siap',
            'status' => Presentation::STATUS_ERROR,
            'visual_template' => 'resmi_klasik',
            'configuration' => ['title' => 'Dokumen Tidak Siap', 'visual_template' => 'resmi_klasik', 'slide_count' => 8],
            'outline' => [['title' => 'Agenda', 'bullets' => ['A', 'B']]],
            'source_document_ids' => [999999],
            'error_message' => 'Gagal.',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('retry', $presentation->id)
            ->assertSee('Dokumen sumber tidak valid atau belum siap. Pilih ulang dokumen lalu coba lagi.');

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        $this->assertSame('Dokumen sumber tidak valid atau belum siap. Pilih ulang dokumen lalu coba lagi.', $presentation->error_message);
        Queue::assertNotPushed(GeneratePresentation::class);
        Http::assertNothingSent();
    }
}
