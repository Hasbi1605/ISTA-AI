<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\AIRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    
    /**
     * The number of seconds the job can run before timing out.
     * 
     * @var int
     */
    public $timeout = 900; // 15 minutes timeout

    /**
     * Create a new job instance.
     */
    public function __construct(public Document $document)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(AIRuntimeService $AIRuntimeService): void
    {
        try {
            $this->document->update(['status' => 'processing']);

            $filePath = Storage::disk('local')->path($this->document->file_path);
            if (!file_exists($filePath)) {
                $filePath = Storage::disk('local')->path('private/' . $this->document->file_path);
            }
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found. Tried: {$this->document->file_path} and private/{$this->document->file_path}");
            }

            $result = $AIRuntimeService->documentProcess(
                $filePath,
                $this->document->original_name,
                $this->document->user_id
            );

            if (($result['status'] ?? 'error') === 'success') {
                $this->document->update(['status' => 'ready']);
            } else {
                throw new Exception("Process failed: " . ($result['message'] ?? 'Unknown error'));
            }

        } catch (Exception $e) {
            $this->document->update(['status' => 'error']);
            logger()->error("Document processing failed for ID {$this->document->id}: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update(['status' => 'error']);
        logger()->error("Document processing permanently failed for ID {$this->document->id}: " . $exception->getMessage());
    }
}
