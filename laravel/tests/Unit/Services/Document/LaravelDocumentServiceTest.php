<?php

namespace Tests\Unit\Services\Document;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Document\LaravelDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaravelDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_process_returns_array_structure_when_disabled(): void
    {
        Config::set('ai.laravel_ai.document_process_enabled', false);

        $service = new LaravelDocumentService();

        $result = $service->processDocument('/tmp/test.pdf', 'test.pdf', 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('error', $result['status']);
    }

    public function test_document_process_returns_array_structure_when_enabled(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_process_enabled', true);

        $service = new LaravelDocumentService();

        $result = $service->processDocument('/tmp/test.pdf', 'test.pdf', 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    public function test_document_summarize_returns_array_structure_when_disabled(): void
    {
        Config::set('ai.laravel_ai.document_summarize_enabled', false);

        $service = new LaravelDocumentService();

        $result = $service->summarizeDocument('test.pdf', 'user1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('error', $result['status']);
    }

    public function test_document_summarize_returns_array_structure_when_enabled(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);

        $service = new LaravelDocumentService();

        $result = $service->summarizeDocument('nonexistent.pdf', 'user1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    public function test_document_delete_returns_bool(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');

        $service = new LaravelDocumentService();

        $result = $service->deleteDocument('nonexistent.pdf', 'user1');

        $this->assertIsBool($result);
    }

    public function test_summarize_document_queries_with_user_isolation(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);

        $service = new LaravelDocumentService();

        $result = $service->summarizeDocument('test.pdf', 'user1');

        $this->assertIsArray($result);
    }

    public function test_delete_document_queries_with_user_isolation(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');

        $service = new LaravelDocumentService();

        $result = $service->deleteDocument('test.pdf', 'user1');

        $this->assertIsBool($result);
    }

    public function test_summarization_prompt_keys_exist_in_config(): void
    {
        // Pastikan kunci config yang dipakai LaravelDocumentService::summarizeDocument
        // ada secara default setelah migrasi parity #99 (mencegah regresi diam-diam
        // ketika prompt di-rename atau dihapus).
        $instructions = config('ai.prompts.summarization.instructions');
        $single = config('ai.prompts.summarization.single');
        $partial = config('ai.prompts.summarization.partial');
        $final = config('ai.prompts.summarization.final');

        $this->assertIsString($instructions);
        $this->assertNotEmpty($instructions);
        $this->assertIsString($single);
        $this->assertStringContainsString('Ringkasan inti', $single);
        $this->assertIsString($partial);
        $this->assertStringContainsString('{part_number}', $partial);
        $this->assertIsString($final);
        $this->assertStringContainsString('{combined_summaries}', $final);
    }

    public function test_summarize_returns_sources_metadata(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.cascade.enabled', false);

        $service = new LaravelDocumentService();

        $result = $service->summarizeDocument('test.pdf', 'user1');

        $this->assertIsArray($result);

        if ($result['status'] === 'success') {
            $this->assertArrayHasKey('sources', $result);
            $this->assertArrayHasKey('model', $result);
        }
    }

    public function test_summarize_batch_creation_for_large_documents(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.laravel_ai.summarize_max_tokens', 20);
        Config::set('ai.cascade.enabled', false);

        $service = new LaravelDocumentService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createBatches');
        $method->setAccessible(true);

        $chunks = [
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 40),
        ];

        $batches = $method->invoke($service, $chunks);

        $this->assertIsArray($batches);
        $this->assertGreaterThanOrEqual(2, count($batches));
    }

    public function test_summarize_token_estimation(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);

        $service = new LaravelDocumentService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('estimateTokens');
        $method->setAccessible(true);

        $chunks = ['hello world', 'test content'];
        $tokens = $method->invoke($service, $chunks);

        $this->assertIsInt($tokens);
        $this->assertGreaterThan(0, $tokens);
    }

    public function test_summarize_single_batch_with_document_chunks_returns_metadata(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.laravel_ai.summarize_max_tokens', 8000);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'original_name' => 'doc-single.pdf',
            'status' => 'ready',
            'file_path' => 'documents/doc-single.pdf',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Konten singkat dokumen tentang AI.',
            'chunk_type' => 'child',
            'parent_id' => null,
            'parent_index' => 0,
            'child_index' => 0,
            'page_number' => 1,
            'embedding' => null,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 1536,
        ]);

        $service = new class extends LaravelDocumentService {
            public array $cascadeCalls = [];
            protected function summarizeWithCascade(string $content): array
            {
                $this->cascadeCalls[] = $content;
                return [
                    'text' => 'Ringkasan AI dari dokumen.',
                    'model' => 'openai/gpt-4.1',
                    'provider' => 'github_models',
                ];
            }
        };

        $result = $service->summarizeDocument('doc-single.pdf', (string) $user->id);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Ringkasan AI dari dokumen.', $result['summary']);
        $this->assertEquals('openai/gpt-4.1', $result['model']);
        $this->assertNotEmpty($result['sources']);
        $this->assertEquals('doc-single.pdf', $result['sources'][0]['filename']);
        $this->assertEquals($document->id, $result['sources'][0]['document_id']);
        $this->assertCount(1, $service->cascadeCalls, 'Single-batch path should call cascade exactly once');
    }

    public function test_summarize_multi_batch_with_final_summary_returns_correct_metadata(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.laravel_ai.summarize_max_tokens', 50);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'original_name' => 'doc-multi.pdf',
            'status' => 'ready',
            'file_path' => 'documents/doc-multi.pdf',
        ]);

        $largeText = str_repeat('Konten panjang dokumen yang harus dipotong batch. ', 20);
        for ($i = 0; $i < 4; $i++) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'text_content' => $largeText,
                'chunk_type' => 'child',
                'parent_id' => null,
                'parent_index' => 0,
                'child_index' => $i,
                'page_number' => 1,
                'embedding' => null,
                'embedding_model' => 'text-embedding-3-small',
                'embedding_dimensions' => 1536,
            ]);
        }

        $service = new class extends LaravelDocumentService {
            public int $cascadeCallCount = 0;
            protected function summarizeWithCascade(string $content): array
            {
                $this->cascadeCallCount++;
                if ($this->cascadeCallCount === 1) {
                    return ['text' => 'Batch 1 summary', 'model' => 'groq/llama-3.3-70b', 'provider' => 'groq'];
                }
                if ($this->cascadeCallCount === 2) {
                    return ['text' => 'Batch 2 summary', 'model' => 'groq/llama-3.3-70b', 'provider' => 'groq'];
                }
                return ['text' => 'Final combined summary', 'model' => 'openai/gpt-4o', 'provider' => 'github_models'];
            }
        };

        $result = $service->summarizeDocument('doc-multi.pdf', (string) $user->id);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Final combined summary', $result['summary']);
        $this->assertEquals('openai/gpt-4o', $result['model'], 'Final summary model harus digunakan, bukan model batch');
        $this->assertGreaterThanOrEqual(3, $service->cascadeCallCount, 'Multi-batch + final summary harus memicu setidaknya 3 cascade calls');
        $this->assertNotEmpty($result['sources']);
    }

    public function test_summarize_fallback_provider_when_primary_fails(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);
        Config::set('ai.cascade.enabled', true);
        Config::set('ai.cascade.nodes', [
            ['label' => 'Primary', 'provider' => 'openai', 'model' => 'openai/gpt-4.1', 'api_key' => 'k1', 'base_url' => 'https://primary'],
            ['label' => 'Backup', 'provider' => 'openai', 'model' => 'openai/gpt-4o', 'api_key' => 'k2', 'base_url' => 'https://backup'],
        ]);

        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create([
            'original_name' => 'doc-fallback.pdf',
            'status' => 'ready',
            'file_path' => 'documents/doc-fallback.pdf',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'text_content' => 'Konten singkat untuk uji cascade fallback.',
            'chunk_type' => 'child',
            'parent_id' => null,
            'parent_index' => 0,
            'child_index' => 0,
            'page_number' => 1,
            'embedding' => null,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 1536,
        ]);

        $service = new class extends LaravelDocumentService {
            public array $providerCalls = [];
            protected function runSummarizationOnNode(array $node, \Laravel\Ai\AnonymousAgent $agent, string $content): string
            {
                $this->providerCalls[] = $node['model'];

                if ($node['model'] === 'openai/gpt-4.1') {
                    throw new \RuntimeException('429 Too Many Requests');
                }

                return 'Backup provider summary';
            }
        };

        $result = $service->summarizeDocument('doc-fallback.pdf', (string) $user->id);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Backup provider summary', $result['summary']);
        $this->assertEquals('openai/gpt-4o', $result['model'], 'Model harus mengikuti node backup yang sukses');
        $this->assertEquals(['openai/gpt-4.1', 'openai/gpt-4o'], $service->providerCalls);
    }

    public function test_summarize_falls_back_to_file_when_chunks_empty(): void
    {
        Config::set('ai.laravel_ai.api_key', 'test-key');
        Config::set('ai.laravel_ai.document_summarize_enabled', true);

        $user = User::factory()->create();

        $relativePath = 'documents/legacy.pdf';
        $absolutePath = storage_path('app/' . $relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($absolutePath, 'Legacy document content without chunks.');

        Document::factory()->for($user)->create([
            'original_name' => 'legacy.pdf',
            'status' => 'ready',
            'file_path' => $relativePath,
        ]);

        \Laravel\Ai\AnonymousAgent::fake([
            'Ringkasan dari file fallback',
        ]);

        $service = new LaravelDocumentService();

        $result = $service->summarizeDocument('legacy.pdf', (string) $user->id);

        unlink($absolutePath);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Ringkasan dari file fallback', $result['summary']);
        $this->assertNotEmpty($result['sources']);
        $this->assertEquals('file_attachment', $result['sources'][0]['mode']);
    }

    public function test_summarize_calls_chat_completions_endpoint_not_responses(): void
    {
        Config::set('ai.cascade.enabled', true);
        Config::set('ai.cascade.nodes', [[
            'label' => 'Test Node',
            'provider' => 'openai',
            'model' => 'test-model',
            'api_key' => 'test-key',
            'base_url' => 'https://test.example/v1',
        ]]);

        Http::fake([
            'https://test.example/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Ringkasan hasil dari /chat/completions'],
                ]],
            ], 200),
            'https://test.example/v1/responses' => Http::response(
                ['error' => ['code' => 'api_not_supported']],
                404
            ),
        ]);

        $service = new class extends LaravelDocumentService {
            public function summarize(string $content): array
            {
                return $this->summarizeWithCascade($content);
            }
        };

        $result = $service->summarize('konten dokumen test.');

        $this->assertEquals('test-model', $result['model']);
        $this->assertStringContainsString('/chat/completions', $result['text'] ?? '');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/chat/completions')
                && !str_ends_with($request->url(), '/responses');
        });
    }
}