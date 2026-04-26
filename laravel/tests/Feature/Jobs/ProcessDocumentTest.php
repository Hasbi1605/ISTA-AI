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

    public function test_job_processes_unsupported_format_triggers_fallback(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $filePath = 'documents/' . $user->id . '/test.txt';
        Storage::disk('local')->put($filePath, 'Plain text content');

        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'test.txt',
            'original_name' => 'test.txt',
            'file_path' => $filePath,
            'mime_type' => 'text/plain',
            'file_size_bytes' => 100,
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
}
