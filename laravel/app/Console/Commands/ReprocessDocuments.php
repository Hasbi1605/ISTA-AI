<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Console\Command;

class ReprocessDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:reprocess {--all : Reprocess all ready and error documents} {--include-pending : Include pending documents when using --all} {--id= : Reprocess specific document ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess ready or failed documents, with optional pending inclusion for full queue replay';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\DocumentLifecycleService $lifecycleService)
    {
        if ($this->option('id')) {
            $document = Document::find($this->option('id'));
            if (!$document) {
                $this->error("Document with ID {$this->option('id')} not found.");
                return 1;
            }
            
            $this->reprocessDocument($document, $lifecycleService);
            return 0;
        }
        
        if ($this->option('all')) {
            $statuses = ['ready', 'error'];

            if ($this->option('include-pending')) {
                $statuses[] = 'pending';
            }

            $documents = Document::whereIn('status', $statuses)->get();
        } else {
            // Default: reprocess only 'ready' documents (might be missing in ChromaDB)
            $documents = Document::where('status', 'ready')->get();
        }
        
        if ($documents->isEmpty()) {
            $this->info('No documents to reprocess.');
            return 0;
        }
        
        $this->info("Found {$documents->count()} documents to reprocess.");
        
        foreach ($documents as $document) {
            $this->reprocessDocument($document, $lifecycleService);
        }
        
        $this->info('All documents queued for reprocessing.');
        return 0;
    }
    
    private function reprocessDocument(Document $document, \App\Services\DocumentLifecycleService $lifecycleService): void
    {
        $this->line("Queueing: [{$document->id}] {$document->original_name}");
        
        // Reset status to pending
        $document->update([
            'status' => 'pending',
            'indexed_chunk_count' => null,
            'embedding_provider' => null,
            'indexed_at' => null,
        ]);
        
        // Dispatch the job
        $lifecycleService->dispatchProcessing($document);
    }
}
