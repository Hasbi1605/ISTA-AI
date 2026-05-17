<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Documents\DocumentPreviewRenderer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderDocumentPreview implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Document $document)
    {
        $this->onQueue('document-previews');
    }

    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    public function handle(DocumentPreviewRenderer $renderer): void
    {
        $fresh = $this->document->fresh();

        if ($fresh === null) {
            logger()->info('RenderDocumentPreview: skipped — document deleted before render started', [
                'document_id' => $this->document->id,
            ]);

            return;
        }

        // Skip if another render already completed while this job was queued.
        // ShouldBeUniqueUntilProcessing prevents duplicate entries in the queue,
        // but a stale job dispatched before the preview was rendered can still
        // arrive after the fact. Guard here to avoid re-rendering unnecessarily.
        if ($fresh->preview_status === Document::PREVIEW_STATUS_READY) {
            return;
        }

        $renderer->render($fresh);
    }

    public function failed(\Throwable $exception): void
    {
        $fresh = $this->document->fresh();

        if ($fresh === null) {
            logger()->info('RenderDocumentPreview failed hook skipped — document already deleted', [
                'document_id' => $this->document->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $fresh->forceFill([
            'preview_status' => Document::PREVIEW_STATUS_FAILED,
        ])->save();

        logger()->error('RenderDocumentPreview job permanently failed', [
            'document_id' => $fresh->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
