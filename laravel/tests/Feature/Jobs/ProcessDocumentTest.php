<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\AIRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Mockery;
use Exception;

class ProcessDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_updates_status_to_ready_on_success(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/dummy.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'dummy.pdf',
            'original_name' => 'dummy.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'pending',
        ]);

        $mockRuntime = Mockery::mock(AIRuntimeService::class);
        $mockRuntime->shouldReceive('documentProcess')
            ->once()
            ->andReturn(['status' => 'success', 'message' => 'ok']);

        $job = new ProcessDocument($document);
        $job->handle($mockRuntime);

        $this->assertEquals('ready', $document->fresh()->status);
    }

    public function test_job_updates_status_to_error_on_http_failure(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/dummy2.pdf';
        Storage::disk('local')->put($filePath, 'dummy content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'dummy2.pdf',
            'original_name' => 'dummy2.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'pending',
        ]);

        $mockRuntime = Mockery::mock(AIRuntimeService::class);
        $mockRuntime->shouldReceive('documentProcess')
            ->once()
            ->andReturn(['status' => 'error', 'message' => 'failed']);

        $job = new ProcessDocument($document);
        $job->handle($mockRuntime);

        $this->assertEquals('error', $document->fresh()->status);
    }

    public function test_job_updates_status_to_error_if_file_missing(): void
    {
        $user = User::factory()->create();

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'missing.pdf',
            'original_name' => 'missing.pdf',
            'file_path' => 'documents/' . $user->id . '/missing.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'pending',
        ]);

        $mockRuntime = Mockery::mock(AIRuntimeService::class);

        $job = new ProcessDocument($document);
        $job->handle($mockRuntime);

        $this->assertEquals('error', $document->fresh()->status);
    }
}
