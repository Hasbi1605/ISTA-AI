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

            // 2. Prepare file
            $filePath = Storage::disk('local')->path($this->document->file_path);
            if (!file_exists($filePath)) {
                throw new Exception("File not found at: {$filePath}");
            }

            // 3. Send to Python Microservice
            $pythonUrl = config('services.ai_service.url', 'http://127.0.0.1:8001') . '/api/documents/process';
            $token = config('services.ai_service.token');

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])
            ->attach(
                'file',
                file_get_contents($filePath),
                $this->document->original_name
            )
            ->post($pythonUrl);

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
}
