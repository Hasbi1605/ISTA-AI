<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ReprocessDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocess_command_dispatches_jobs_via_lifecycle_service()
    {
        Queue::fake();

        $user = User::factory()->create();
        $doc = Document::create([
            'user_id' => $user->id,
            'filename' => 'doc.pdf',
            'original_name' => 'doc.pdf',
            'file_path' => 'path/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        $this->artisan('documents:reprocess', ['--id' => $doc->id])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessDocument::class, function ($job) use ($doc) {
            return $job->document->id === $doc->id;
        });
        
        $this->assertEquals('pending', $doc->fresh()->status);
    }
    
    public function test_reprocess_command_uses_document_lifecycle_service()
    {
        $user = User::factory()->create();
        $doc = Document::create([
            'user_id' => $user->id,
            'filename' => 'doc.pdf',
            'original_name' => 'doc.pdf',
            'file_path' => 'path/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);

        $this->mock(DocumentLifecycleService::class, function (MockInterface $mock) use ($doc) {
            $mock->shouldReceive('dispatchProcessing')
                ->once()
                ->withArgs(function ($documentArg) use ($doc) {
                    return $documentArg->id === $doc->id;
                });
        });

        $this->artisan('documents:reprocess', ['--id' => $doc->id])
            ->assertExitCode(0);
            
        $this->assertEquals('pending', $doc->fresh()->status);
    }
}
