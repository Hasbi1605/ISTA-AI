<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];

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
    public function handle(): void
    {
        try {
            // 1. Update status to processing
            $this->document->update(['status' => 'processing']);

            // 2. Prepare file - Try both private and public paths
            $filePath = Storage::disk('local')->path($this->document->file_path);
            
            // Laravel 11+ stores in private/ by default
            if (!file_exists($filePath)) {
                $filePath = Storage::disk('local')->path('private/' . $this->document->file_path);
            }
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found. Tried: {$this->document->file_path} and private/{$this->document->file_path}");
            }

            // 3. Send to Python Microservice (with extended timeout for embedding)
            $pythonUrl = config('services.ai_service.url', 'http://127.0.0.1:8001') . '/api/documents/process';
            $token = config('services.ai_service.token');

            $response = Http::timeout(300) // 5 minutes timeout for large documents
                ->withHeaders([
                    'Authorization' => "Bearer {$token}",
                ])
                ->attach(
                    'file',
                    file_get_contents($filePath),
                    $this->document->original_name
                )
                ->post($pythonUrl, [
                    'user_id' => (string) $this->document->user_id,
                ]);

            if ($response->successful()) {
                // 4. Update status to ready
                $this->document->update(['status' => 'ready']);
            } else {
                throw new Exception("Microservice error: " . $response->body());
            }

        } catch (Exception $e) {
            $this->document->update(['status' => 'error']);
            // Log the error
            logger()->error("Document processing failed for ID {$this->document->id}: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update(['status' => 'error']);
        logger()->error("Document processing permanently failed for ID {$this->document->id}: " . $exception->getMessage());
    }
}
