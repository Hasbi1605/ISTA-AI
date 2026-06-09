<?php

namespace Tests\Feature\Documents;

use App\Livewire\Documents\DocumentViewer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentViewerLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_starts_closed(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->assertSet('isOpen', false)
            ->assertSet('documentId', null);
    }

    public function test_viewer_opens_for_owned_document(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'sample.pdf',
            'original_name' => 'sample.pdf',
            'file_path' => 'documents/'.$user->id.'/sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSet('isOpen', true)
            ->assertSet('documentId', $document->id)
            ->assertSee($document->original_name);
    }

    public function test_missing_pdf_source_shows_server_side_fallback(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'sample.pdf',
            'original_name' => 'sample.pdf',
            'file_path' => 'documents/'.$user->id.'/sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSee('Preview PDF gagal dimuat')
            ->assertSee('File sumber PDF tidak tersedia')
            ->assertDontSee('Buka PDF di tab baru')
            ->assertDontSeeHtml('<iframe')
            ->assertDontSeeHtml('sandbox=');
    }

    public function test_viewer_renders_table_export_controls_for_extractable_document(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'sample.pdf',
            'original_name' => 'sample.pdf',
            'file_path' => 'documents/'.$user->id.'/sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSee('wire:key="document-export-actions-'.$document->id.'"', false)
            ->assertSee('data-document-export-actions', false)
            ->assertSee(str_replace('/', '\/', route('documents.content-html', $document)), false)
            ->assertSee(str_replace('/', '\/', route('documents.extract-tables', $document)), false)
            ->assertSee('Ekspor', false)
            ->assertSee('data-document-export-format="xlsx"', false)
            ->assertSee('data-document-export-format="csv"', false)
            ->assertSee('data-document-export-format="docx"', false)
            ->assertSee('data-document-export-format="pdf"', false)
            ->assertSee(':aria-label="exportLoadingLabel()"', false)
            ->assertSee('x-show="isExportLoading()"', false)
            ->assertSee(':disabled="isBusy()"', false)
            ->assertDontSee('Simpan ke Google Drive', false)
            ->assertDontSee('images/icons/google-drive.svg', false)
            ->assertDontSee('Upload ke Drive', false)
            ->assertDontSee('data-document-drive-format', false);
    }

    public function test_viewer_renders_export_controls_for_xlsx_document(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'budget.xlsx',
            'original_name' => 'budget.xlsx',
            'file_path' => 'documents/'.$user->id.'/budget.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSee('wire:key="document-export-actions-'.$document->id.'"', false)
            ->assertSee('data-document-export-actions', false)
            ->assertSee(str_replace('/', '\/', route('documents.content-html', $document)), false)
            ->assertDontSee(str_replace('/', '\/', route('documents.extract-tables', $document)), false)
            ->assertSee('preferTableExtraction: false', false)
            ->assertSee('Ekspor', false)
            ->assertSee('data-document-export-format="xlsx"', false)
            ->assertSee('data-document-export-format="csv"', false)
            ->assertSee('data-document-export-format="docx"', false)
            ->assertSee('data-document-export-format="pdf"', false);
    }

    public function test_viewer_renders_export_controls_for_csv_document(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'budget.csv',
            'original_name' => 'budget.csv',
            'file_path' => 'documents/'.$user->id.'/budget.csv',
            'mime_type' => 'text/csv',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSee('wire:key="document-export-actions-'.$document->id.'"', false)
            ->assertSee('data-document-export-actions', false)
            ->assertSee(str_replace('/', '\/', route('documents.content-html', $document)), false)
            ->assertDontSee(str_replace('/', '\/', route('documents.extract-tables', $document)), false)
            ->assertSee('preferTableExtraction: false', false)
            ->assertSee('Ekspor', false)
            ->assertSee('data-document-export-format="xlsx"', false)
            ->assertSee('data-document-export-format="csv"', false)
            ->assertSee('data-document-export-format="docx"', false)
            ->assertSee('data-document-export-format="pdf"', false);
    }

    public function test_viewer_does_not_render_other_users_document(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $document = Document::create([
            'user_id' => $owner->id,
            'filename' => 'private.pdf',
            'original_name' => 'private.pdf',
            'file_path' => 'documents/'.$owner->id.'/private.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);

        Livewire::actingAs($other)
            ->test(DocumentViewer::class)
            ->call('open', $document->id)
            ->assertSet('isOpen', true)
            ->assertDontSee('private.pdf')
            ->assertSee('Dokumen tidak ditemukan');
    }

    public function test_viewer_closes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->set('isOpen', true)
            ->set('documentId', 999)
            ->call('close')
            ->assertSet('isOpen', false)
            ->assertSet('documentId', null);
    }

    public function test_viewer_has_optimistic_open_and_close_hooks(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DocumentViewer::class)
            ->assertSee('x-on:open-document-preview.window="open($event.detail.documentId)"', false)
            ->assertSee('x-show="isVisible"', false)
            ->assertSee('@click="close()"', false)
            ->assertSee('Membuka dokumen...', false);
    }
}
