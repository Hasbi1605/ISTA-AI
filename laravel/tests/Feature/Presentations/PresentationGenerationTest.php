<?php

namespace Tests\Feature\Presentations;

use App\Jobs\GeneratePresentation;
use App\Models\Document;
use App\Models\Presentation;
use App\Models\User;
use App\Services\Presentations\PresentationGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PresentationGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function fakePptxBytes(): string
    {
        // PPTX adalah arsip ZIP (magic "PK"); panjang > 100 byte agar lolos guard.
        return 'PK'.str_repeat("\x00pptx-content", 30);
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

    private function pendingPresentation(User $user, array $attributes = []): Presentation
    {
        return Presentation::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Paparan Uji',
            'status' => Presentation::STATUS_PENDING,
            'visual_template' => 'resmi_klasik',
            'configuration' => ['title' => 'Paparan Uji', 'visual_template' => 'resmi_klasik', 'slide_count' => 6],
            'outline' => [['title' => 'Agenda', 'bullets' => ['A', 'B']]],
            'source_document_ids' => [],
        ], $attributes));
    }

    public function test_create_and_dispatch_creates_pending_and_queues_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $doc = $this->makeDocument($user, 'ready');

        $presentation = app(PresentationGenerationService::class)->createAndDispatch($user, [
            'title' => 'Rapat Koordinasi',
            'visual_template' => 'modern_minimal',
            'slide_count' => 6,
            'source_document_ids' => [$doc->id],
            'additional_instruction' => "Poin satu\nPoin dua",
        ]);

        $this->assertSame(Presentation::STATUS_PENDING, $presentation->status);
        $this->assertSame('modern_minimal', $presentation->visual_template);
        $this->assertSame([(int) $doc->id], $presentation->source_document_ids);
        $this->assertNotEmpty($presentation->outline);
        Queue::assertPushed(GeneratePresentation::class);
    }

    public function test_create_and_dispatch_filters_out_foreign_documents(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->makeDocument($other, 'ready');

        $presentation = app(PresentationGenerationService::class)->createAndDispatch($user, [
            'title' => 'Deck',
            'visual_template' => 'resmi_klasik',
            'source_document_ids' => [$foreign->id],
        ]);

        $this->assertSame([], $presentation->source_document_ids);
    }

    public function test_job_generates_and_stores_pptx_with_ready_status(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/presentations/generate' => Http::response($this->fakePptxBytes(), 200, [
                'X-Presentation-Template' => 'resmi_klasik',
                'X-Presentation-Slide-Count' => '5',
            ]),
        ]);

        $user = User::factory()->create();
        $presentation = $this->pendingPresentation($user);

        $job = new GeneratePresentation($presentation);
        app()->call([$job, 'handle']);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_READY, $presentation->status);
        $this->assertNotNull($presentation->pptx_path);
        $this->assertNull($presentation->pdf_path);
        $this->assertNotNull($presentation->generated_at);
        Storage::disk('local')->assertExists($presentation->pptx_path);
        $this->assertStringStartsWith('presentations/'.$user->id.'/', $presentation->pptx_path);
    }

    public function test_job_records_active_version_on_success(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/presentations/generate' => Http::response($this->fakePptxBytes(), 200, [
                'X-Presentation-Template' => 'resmi_klasik',
                'X-Presentation-Slide-Count' => '5',
            ]),
        ]);

        $user = User::factory()->create();
        $presentation = $this->pendingPresentation($user);

        $job = new GeneratePresentation($presentation);
        app()->call([$job, 'handle']);

        $presentation->refresh();
        $this->assertNotNull($presentation->current_version_id);
        $version = $presentation->currentVersion;
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame($presentation->pptx_path, $version->pptx_path);
        $this->assertSame(\App\Models\PresentationVersion::STATUS_GENERATED, $version->status);
    }

    public function test_job_marks_error_when_python_request_fails(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/presentations/generate' => Http::response('boom', 500),
        ]);

        $user = User::factory()->create();
        $presentation = $this->pendingPresentation($user);

        $job = new GeneratePresentation($presentation);

        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        $this->assertNotNull($presentation->error_message);
        $this->assertNull($presentation->pptx_path);
    }

    public function test_job_rejects_foreign_source_documents_without_calling_python(): void
    {
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->makeDocument($other, 'ready');

        $presentation = $this->pendingPresentation($user, ['source_document_ids' => [$foreign->id]]);

        $job = new GeneratePresentation($presentation);
        app()->call([$job, 'handle']);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        Http::assertNothingSent();
    }

    public function test_job_rejects_not_ready_source_documents(): void
    {
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create();
        $processing = $this->makeDocument($user, 'processing');

        $presentation = $this->pendingPresentation($user, ['source_document_ids' => [$processing->id]]);

        $job = new GeneratePresentation($presentation);
        app()->call([$job, 'handle']);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        Http::assertNothingSent();
    }
}
