<?php

namespace Tests\Feature\Presentations;

use App\Jobs\GeneratePresentation;
use App\Livewire\Presentations\PresentationWorkspace;
use App\Models\Document;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class PresentationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_workspace_renders_configuration_form_and_submodes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->assertSee('Konfigurasi Presentasi')
            ->assertSee('Buat PPT ISTA')
            ->assertSee('Prompy Studio')
            ->assertSee('Resmi Klasik')
            ->assertSee('Modern Minimal');
    }

    public function test_prompy_submode_shows_placeholder(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('setSubMode', 'prompy')
            ->assertSee('Prompy Studio')
            ->assertSee('Segera hadir');
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

    public function test_edit_presentation_builds_slides_editor_config(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'editor-secret',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);
        \Illuminate\Support\Facades\Storage::fake('local');

        $user = User::factory()->create();
        $path = 'presentations/'.$user->id.'/deck.pptx';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'PK'.str_repeat('x', 200));

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Paparan Edit',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
            'pptx_path' => $path,
            'generated_at' => now(),
        ]);
        $version = $presentation->versions()->create([
            'version_number' => 1,
            'label' => 'Versi 1',
            'pptx_path' => $path,
            'status' => \App\Models\PresentationVersion::STATUS_GENERATED,
        ]);
        $presentation->forceFill(['current_version_id' => $version->id])->save();

        $component = Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('editPresentation', $presentation->id)
            ->assertSet('editingPresentationId', $presentation->id);

        $config = $component->instance()->editorConfig();

        $this->assertIsArray($config);
        $this->assertSame('slide', $config['documentType']);
        $this->assertSame('pptx', $config['document']['fileType']);
        $this->assertStringStartsWith('presentation-'.$presentation->id.'-', $config['document']['key']);
        $this->assertStringContainsString('/onlyoffice/presentation-callback/'.$presentation->id, $config['editorConfig']['callbackUrl']);
        $this->assertArrayHasKey('token', $config);
    }

    public function test_edit_presentation_rejects_non_ready(): void
    {
        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Belum Siap',
            'status' => Presentation::STATUS_PROCESSING,
            'visual_template' => 'resmi_klasik',
        ]);

        Livewire::actingAs($user)
            ->test(PresentationWorkspace::class)
            ->call('editPresentation', $presentation->id)
            ->assertSet('editingPresentationId', null);
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
}
