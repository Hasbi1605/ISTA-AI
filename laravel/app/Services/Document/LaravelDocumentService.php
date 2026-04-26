<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Prompts\AgentPrompt;

class LaravelDocumentService
{
    protected AiManager $ai;
    protected string $model;
    protected int $maxTokensPerBatch;
    protected bool $cascadeEnabled;
    protected array $cascadeNodes;

    public function __construct()
    {
        $this->ai = app(AiManager::class);
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
        $this->maxTokensPerBatch = config('ai.laravel_ai.summarize_max_tokens', 8000);
        $this->cascadeEnabled = config('ai.cascade.enabled', true);
        $this->cascadeNodes = config('ai.cascade.nodes', []);
    }

    public function processDocument(string $filePath, string $originalName, int $userId): array
    {
        if (!config('ai.laravel_ai.document_process_enabled', false)) {
            return [
                'status' => 'error',
                'message' => 'Document process belum diaktifkan.',
            ];
        }

        try {
            $aiDoc = AiDocument::fromPath($filePath);
            $storedFile = Files::put($aiDoc, null, $originalName);

            Log::info('LaravelDocumentService: document processed', [
                'file' => $originalName,
                'provider_id' => $storedFile->id ?? null,
            ]);

            return [
                'status' => 'success',
                'message' => 'Dokumen berhasil disimpan ke provider',
                'provider_file_id' => $storedFile->id ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentService: process failed', [
                'file' => $originalName,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Gagal memproses dokumen: ' . $e->getMessage(),
            ];
        }
    }

    public function summarizeDocument(string $filename, ?string $userId = null): array
    {
        if (!config('ai.laravel_ai.document_summarize_enabled', false)) {
            return [
                'status' => 'error',
                'message' => 'Document summarize belum diaktifkan.',
            ];
        }

        try {
            $query = Document::where('original_name', $filename);
            if ($userId !== null) {
                $query->where('user_id', (int) $userId);
            }
            $document = $query->first();

            if (!$document) {
                return [
                    'status' => 'error',
                    'message' => 'Dokumen tidak ditemukan.',
                ];
            }

            $chunks = $this->getChunksForSummarization($document->id);

            if (empty($chunks)) {
                return $this->summarizeFromFile($document, $filename);
            }

            $totalTokens = $this->estimateTokens($chunks);
            $sources = [
                ['filename' => $filename, 'document_id' => $document->id, 'chunks' => count($chunks), 'tokens' => $totalTokens]
            ];

            if ($totalTokens <= $this->maxTokensPerBatch) {
                $content = implode("\n\n", $chunks);
                $result = $this->summarizeWithCascade($content);
                $summary = $result['text'] ?? '';
                $usedModel = $result['model'] ?? $this->model;
            } else {
                $batches = $this->createBatches($chunks);
                $batchSummaries = [];

                foreach ($batches as $batchContent) {
                    $batchResult = $this->summarizeWithCascade($batchContent);
                    if (!empty($batchResult['text'])) {
                        $batchSummaries[] = $batchResult['text'];
                    }
                }

                if (empty($batchSummaries)) {
                    return [
                        'status' => 'error',
                        'message' => 'Gagal merangkum dokumen.',
                    ];
                }

                $combinedSummary = implode("\n\n", $batchSummaries);

                if (count($batchSummaries) > 1) {
                    $finalResult = $this->summarizeWithCascade($combinedSummary);
                    $summary = $finalResult['text'] ?? $combinedSummary;
                    $usedModel = $finalResult['model'] ?? $this->model;
                } else {
                    $summary = $combinedSummary;
                    $usedModel = $batchResult['model'] ?? $this->model;
                }
            }

            Log::info('LaravelDocumentService: document summarized', [
                'file' => $filename,
                'content_length' => strlen($summary),
                'chunks_count' => count($chunks),
                'tokens' => $totalTokens,
                'model' => $usedModel,
            ]);

            return [
                'status' => 'success',
                'summary' => $summary,
                'model' => $usedModel,
                'sources' => $sources,
            ];
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentService: summarize failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Gagal merangkum dokumen: ' . $e->getMessage(),
            ];
        }
    }

    protected function summarizeFromFile(Document $document, string $filename): array
    {
        $relativePath = $document->file_path ?? '';
        if ($relativePath === '') {
            return [
                'status' => 'error',
                'message' => 'Tidak ada chunks dan file dokumen tidak tersedia untuk diringkas.',
            ];
        }

        $absolutePath = storage_path('app/' . ltrim($relativePath, '/'));
        if (!file_exists($absolutePath)) {
            $absolutePath = storage_path('app/private/' . ltrim($relativePath, '/'));
        }

        if (!file_exists($absolutePath)) {
            return [
                'status' => 'error',
                'message' => 'File dokumen tidak ditemukan di storage untuk diringkas.',
            ];
        }

        try {
            $aiDoc = AiDocument::fromPath($absolutePath);

            $agent = AnonymousAgent::make(
                instructions: 'Anda adalah asisten AI yang merangkum dokumen. Berikan ringkasan singkat dan akurat.',
                messages: [],
                tools: []
            );

            $result = $agent->prompt(
                'Tolong rangkum dokumen berikut:',
                attachments: [$aiDoc],
                model: $this->model,
            );

            $summary = $result->text ?? '';

            Log::info('LaravelDocumentService: document summarized from file', [
                'file' => $filename,
                'document_id' => $document->id,
                'content_length' => strlen($summary),
                'model' => $this->model,
            ]);

            return [
                'status' => 'success',
                'summary' => $summary,
                'model' => $this->model,
                'sources' => [[
                    'filename' => $filename,
                    'document_id' => $document->id,
                    'mode' => 'file_attachment',
                ]],
            ];
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentService: summarize from file failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Gagal merangkum dokumen dari file: ' . $e->getMessage(),
            ];
        }
    }

    protected function getChunksForSummarization(int $documentId): array
    {
        return DocumentChunk::where('document_id', $documentId)
            ->where('chunk_type', 'child')
            ->orderBy('parent_index')
            ->orderBy('child_index')
            ->pluck('text_content')
            ->toArray();
    }

    protected function estimateTokens(array $chunks): int
    {
        $total = 0;
        foreach ($chunks as $chunk) {
            $total += (int) (strlen($chunk) / 4);
        }
        return $total;
    }

    protected function createBatches(array $chunks): array
    {
        $batches = [];
        $currentBatch = [];
        $currentTokens = 0;

        foreach ($chunks as $chunk) {
            $chunkTokens = (int) (strlen($chunk) / 4);

            if ($currentTokens + $chunkTokens > $this->maxTokensPerBatch && !empty($currentBatch)) {
                $batches[] = implode("\n\n", $currentBatch);
                $currentBatch = [$chunk];
                $currentTokens = $chunkTokens;
            } else {
                $currentBatch[] = $chunk;
                $currentTokens += $chunkTokens;
            }
        }

        if (!empty($currentBatch)) {
            $batches[] = implode("\n\n", $currentBatch);
        }

        return $batches;
    }

    protected function getProviderForNode(array $node, $agent = null)
    {
        $configKey = 'ai.providers.temp_cascade';
        config([$configKey => [
            'driver' => $node['provider'],
            'key' => $node['api_key'],
            'url' => $node['base_url'] ?? null,
            'models' => [
                'text' => [
                    'default' => $node['model'],
                ],
            ],
        ]]);

        if ($agent) {
            return app(\Laravel\Ai\AiManager::class)->textProviderFor($agent, 'temp_cascade');
        }

        return app(\Laravel\Ai\AiManager::class)->textProvider('temp_cascade');
    }

    protected function summarizeWithCascade(string $content): array
    {
        $nodes = $this->cascadeEnabled && !empty($this->cascadeNodes)
            ? $this->cascadeNodes
            : [['label' => 'Default', 'provider' => 'openai', 'model' => $this->model, 'api_key' => config('ai.laravel_ai.api_key')]];

        $agent = AnonymousAgent::make(
            instructions: 'Anda adalah asisten AI yang merangkum dokumen. Berikan ringkasan singkat dan akurat dari bagian dokumen yang diberikan.',
            messages: [],
            tools: []
        );

        foreach ($nodes as $node) {
            try {
                $text = $this->runSummarizationOnNode($node, $agent, $content);
                return [
                    'text' => $text,
                    'model' => $node['model'],
                    'provider' => $node['provider'],
                ];
            } catch (\Throwable $e) {
                Log::warning('LaravelDocumentService: provider failed, trying next', [
                    'provider' => $node['provider'],
                    'model' => $node['model'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        $text = $this->runSummarizationDefault($agent, $content);

        return [
            'text' => $text,
            'model' => $this->model,
            'provider' => 'default',
        ];
    }

    protected function runSummarizationOnNode(array $node, AnonymousAgent $agent, string $content): string
    {
        $provider = $this->getProviderForNode($node, $agent);

        $prompt = new AgentPrompt(
            agent: $agent,
            prompt: 'Rangkum bagian dokumen berikut dengan detail dan akurat: ' . $content,
            attachments: [],
            provider: $provider,
            model: $node['model'],
        );

        $result = $provider->prompt($prompt);

        return $result->text ?? '';
    }

    protected function runSummarizationDefault(AnonymousAgent $agent, string $content): string
    {
        $defaultProvider = $this->ai->textProvider();

        $result = $defaultProvider->prompt(new AgentPrompt(
            agent: $agent,
            prompt: 'Rangkum bagian dokumen berikut dengan detail dan akurat: ' . $content,
            attachments: [],
            provider: $defaultProvider,
            model: $this->model,
        ));

        return $result->text ?? '';
    }

    public function deleteDocument(string $filename, ?string $userId = null): bool
    {
        try {
            $query = Document::where('original_name', $filename);
            if ($userId !== null) {
                $query->where('user_id', (int) $userId);
            }
            $document = $query->first();

            if (!$document) {
                return true;
            }

            if ($document->provider_file_id) {
                try {
                    $file = Files::get($document->provider_file_id);
                    if ($file) {
                        Files::delete($file->id);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Provider file cleanup failed', [
                        'id' => $document->provider_file_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $document->delete();

            Log::info('LaravelDocumentService: document deleted', [
                'file' => $filename,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('LaravelDocumentService: delete failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}