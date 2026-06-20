<?php

namespace Tests\Feature\Presentations;

use App\Models\Document;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationModelTest extends TestCase
{
    use RefreshDatabase;

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

    private function makePresentation(User $user, array $attributes = []): Presentation
    {
        return Presentation::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Paparan Internal',
            'status' => Presentation::STATUS_PENDING,
        ], $attributes));
    }

    public function test_owner_can_view_and_download_ready_presentation(): void
    {
        $owner = User::factory()->create();
        $presentation = $this->makePresentation($owner, ['status' => Presentation::STATUS_READY]);

        $this->assertTrue($owner->can('view', $presentation));
        $this->assertTrue($owner->can('update', $presentation));
        $this->assertTrue($owner->can('delete', $presentation));
        $this->assertTrue($owner->can('download', $presentation));
    }

    public function test_non_owner_cannot_view_or_download_presentation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $presentation = $this->makePresentation($owner, ['status' => Presentation::STATUS_READY]);

        $this->assertFalse($intruder->can('view', $presentation));
        $this->assertFalse($intruder->can('update', $presentation));
        $this->assertFalse($intruder->can('delete', $presentation));
        $this->assertFalse($intruder->can('download', $presentation));
    }

    public function test_download_is_blocked_until_presentation_is_ready(): void
    {
        $owner = User::factory()->create();

        foreach ([Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING, Presentation::STATUS_ERROR] as $status) {
            $presentation = $this->makePresentation($owner, ['status' => $status]);
            $this->assertFalse($owner->can('download', $presentation), "download should be blocked for status {$status}");
        }
    }

    public function test_sanitize_source_document_ids_keeps_only_owned_ready_documents(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $ownedReady = $this->makeDocument($user, 'ready');
        $ownedProcessing = $this->makeDocument($user, 'processing');
        $foreignReady = $this->makeDocument($other, 'ready');

        $result = Presentation::sanitizeSourceDocumentIds($user->id, [
            $ownedReady->id,
            $ownedProcessing->id,
            $foreignReady->id,
            999999,
        ]);

        $this->assertSame([(int) $ownedReady->id], $result);
    }

    public function test_source_documents_owned_and_ready_rejects_foreign_or_unready_ids(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $ownedReady = $this->makeDocument($user, 'ready');
        $ownedProcessing = $this->makeDocument($user, 'processing');
        $foreignReady = $this->makeDocument($other, 'ready');

        $this->assertTrue(Presentation::sourceDocumentsOwnedAndReady($user->id, [$ownedReady->id]));
        $this->assertFalse(Presentation::sourceDocumentsOwnedAndReady($user->id, [$ownedReady->id, $foreignReady->id]));
        $this->assertFalse(Presentation::sourceDocumentsOwnedAndReady($user->id, [$ownedProcessing->id]));
        $this->assertTrue(Presentation::sourceDocumentsOwnedAndReady($user->id, []));
    }

    public function test_status_lifecycle_and_json_casts_persist(): void
    {
        $user = User::factory()->create();
        $document = $this->makeDocument($user, 'ready');

        $presentation = $this->makePresentation($user, [
            'visual_template' => 'modern_minimal',
            'configuration' => ['audience' => 'Kepala Istana', 'slide_count' => 8],
            'outline' => [['title' => 'Agenda', 'bullets' => ['Poin 1']]],
            'source_document_ids' => [$document->id],
        ]);

        $presentation->forceFill([
            'status' => Presentation::STATUS_PROCESSING,
        ])->save();
        $presentation->forceFill([
            'status' => Presentation::STATUS_READY,
            'pptx_path' => 'presentations/'.$user->id.'/'.$presentation->id.'.pptx',
            'generated_at' => now(),
        ])->save();

        $fresh = $presentation->fresh();

        $this->assertSame(Presentation::STATUS_READY, $fresh->status);
        $this->assertTrue($fresh->isReady());
        $this->assertSame('modern_minimal', $fresh->visual_template);
        $this->assertSame('Kepala Istana', $fresh->configuration['audience']);
        $this->assertSame(8, $fresh->configuration['slide_count']);
        $this->assertSame([(int) $document->id], $fresh->source_document_ids);
        $this->assertNotNull($fresh->generated_at);
    }

    public function test_deleting_user_cascades_to_presentations(): void
    {
        $user = User::factory()->create();
        $presentation = $this->makePresentation($user);

        $user->delete();

        $this->assertDatabaseMissing('presentations', ['id' => $presentation->id]);
    }
}
