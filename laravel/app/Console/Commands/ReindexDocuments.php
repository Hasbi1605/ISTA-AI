<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Document\LaravelDocumentService;
use Illuminate\Console\Command;

class ReindexDocuments extends Command
{
    protected $signature = 'documents:reindex 
                            {--dry-run : Only show what would be reindexed without processing}
                            {--user= : Only reindex documents for a specific user ID}
                            {--limit= : Limit number of documents to process}';

    protected $description = 'Re-index all documents to Laravel AI storage provider';

    public function __construct(
        protected LaravelDocumentService $documentService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Document::where('status', 'active');

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
            $this->info("Filtering to user ID: {$userId}");
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $documents = $query->orderBy('created_at', 'desc')->get();
        $total = $documents->count();

        if ($total === 0) {
            $this->warn('No documents found to reindex.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} document(s) to reindex.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run mode - no documents will be processed.');
            $this->table(
                ['ID', 'Filename', 'User ID', 'Original Name', 'Size'],
                $documents->map(fn($doc) => [
                    $doc->id,
                    $doc->filename,
                    $doc->user_id,
                    $doc->original_name,
                    $doc->file_size_bytes ? number_format($doc->file_size_bytes) . ' bytes' : 'N/A',
                ])
            );
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($documents as $document) {
            try {
                $filePath = storage_path('app/documents/' . $document->filename);

                if (!file_exists($filePath)) {
                    $this->newLine();
                    $this->warn("File not found for document {$document->id}: {$filePath}");
                    $failed++;
                    $bar->advance();
                    continue;
                }

                $result = $this->documentService->processDocument(
                    $filePath,
                    $document->original_name,
                    $document->user_id
                );

                if (isset($result['error'])) {
                    $failed++;
                    $errors[] = [
                        'id' => $document->id,
                        'filename' => $document->filename,
                        'error' => $result['error'],
                    ];
                } else {
                    $success++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'id' => $document->id,
                    'filename' => $document->filename,
                    'error' => $e->getMessage(),
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Re-index complete: {$success} succeeded, {$failed} failed.");

        if (!empty($errors)) {
            $this->warn('Errors encountered:');
            foreach ($errors as $error) {
                $this->line("  - Document {$error['id']} ({$error['filename']}): {$error['error']}");
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}