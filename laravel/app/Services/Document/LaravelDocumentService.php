<?php

namespace App\Services\Document;

use App\Models\Document;
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

    public function __construct()
    {
        $this->ai = app(AiManager::class);
        $this->model = config('ai.laravel_ai.model', 'gpt-4o-mini');
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

            $provider = $this->ai->textProvider();
            $aiDoc = AiDocument::fromPath(storage_path('app/' . $document->file_path));

            $agent = AnonymousAgent::make(
                instructions: 'Anda adalah asisten AI yang merangkum dokumen. Berikan ringkasan singkat dan akurat.',
                messages: [],
                tools: []
            );

            $prompt = new AgentPrompt(
                agent: $agent,
                prompt: 'Tolong rangkum dokumen berikut:',
                attachments: [$aiDoc],
                provider: $provider,
                model: $this->model,
            );

            $result = $provider->prompt($prompt);
            $content = $result->text ?? '';

            Log::info('LaravelDocumentService: document summarized', [
                'file' => $filename,
                'content_length' => strlen($content),
            ]);

            return [
                'status' => 'success',
                'summary' => $content,
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