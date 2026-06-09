<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Services\Documents\DocumentPreviewRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class DocumentViewer extends Component
{
    public ?int $documentId = null;

    public bool $isOpen = false;

    public function open(int $documentId): void
    {
        $this->documentId = $documentId;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->documentId = null;
    }

    public function render()
    {
        $document = $this->resolveDocument();
        $kind = null;
        $previewStatus = null;
        $streamUrl = null;
        $htmlUrl = null;
        $pdfPreviewAvailable = false;

        if ($document !== null) {
            $renderer = app(DocumentPreviewRenderer::class);
            $previewStatus = $document->preview_status;

            $kind = match (true) {
                $renderer->isPdf($document) => 'pdf',
                $renderer->isDocx($document) => 'docx',
                $renderer->isXlsx($document) => 'xlsx',
                $renderer->isCsv($document) => 'csv',
                default => 'unknown',
            };

            if ($kind === 'pdf') {
                $streamUrl = route('documents.preview.stream', $document);
                $pdfPreviewAvailable = $this->resolveSourcePath($document) !== null;
            } elseif (in_array($kind, ['docx', 'xlsx', 'csv'], true)) {
                $htmlUrl = route('documents.preview.html', $document);
            }
        }

        return view('livewire.documents.document-viewer', [
            'document' => $document,
            'kind' => $kind,
            'previewStatus' => $previewStatus,
            'streamUrl' => $streamUrl,
            'htmlUrl' => $htmlUrl,
            'pdfPreviewAvailable' => $pdfPreviewAvailable,
        ]);
    }

    protected function resolveSourcePath(Document $document): ?string
    {
        foreach ([$document->file_path, 'private/'.$document->file_path] as $candidate) {
            if (! $candidate) {
                continue;
            }

            $absolute = Storage::disk(DocumentPreviewRenderer::DISK)->path($candidate);
            if (is_file($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    private function resolveDocument(): ?Document
    {
        if (! $this->isOpen || $this->documentId === null) {
            return null;
        }

        return Document::query()
            ->where('user_id', Auth::id())
            ->find($this->documentId);
    }
}
