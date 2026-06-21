<?php

namespace App\Services\Prompts;

use App\Models\AIUsageEvent;
use App\Models\Document;
use App\Models\GeneratedPrompt;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Prompy Studio.
 *
 * Menyusun paket prompt profesional untuk platform AI eksternal lewat python-ai
 * (`/api/prompts/generate`) lalu menyimpan riwayat milik user. ISTA AI TIDAK
 * memanggil platform eksternal dan tidak menghasilkan gambar/video langsung.
 *
 * Privasi: ide, catatan, paket prompt, dan reference image tidak di-log.
 */
class PromptStudioService
{
    /** Platform awal yang didukung (selaras dengan ai_config.yaml #263). */
    public const PLATFORMS = [
        'gpt_image_2' => 'GPT Image 2',
        'gemini_nano_banana' => 'Gemini / Nano Banana',
        'canva_ai' => 'Canva AI',
        'google_flow' => 'Google Flow',
        'generic' => 'Generic',
    ];

    /** Jenis keluaran prompt yang didukung. */
    public const PROMPT_TYPES = [
        'image' => 'Gambar',
        'presentation' => 'Presentasi',
        'poster_infographic' => 'Poster / Infografis',
        'video_storyboard' => 'Video / Storyboard',
    ];

    public const IDEA_MAX_LENGTH = 4000;

    public const CONTEXT_NOTES_MAX_LENGTH = 4000;

    /** Reference image privat (MVP): tipe & ukuran yang diizinkan. */
    public const REFERENCE_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const REFERENCE_IMAGE_MAX_BYTES = 5_242_880; // 5 MB

    public const SOURCE_DOCUMENT_CONTEXT_MAX_LENGTH = 8000;

    private const SOURCE_DOCUMENT_CONTEXT_DOCUMENT_LIMIT = 5;

    private const SOURCE_DOCUMENT_CONTEXT_CHUNK_LIMIT = 3;

    private const SOURCE_DOCUMENT_CONTEXT_CHUNK_MAX_LENGTH = 1200;

    protected string $baseUrl;

    protected ?string $token;

    protected int $connectTimeout;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ai_document_service.url', 'http://127.0.0.1:8001'), '/');
        $this->token = config('services.ai_document_service.token');
        $this->connectTimeout = max(1, (int) config('services.ai_document_service.connect_timeout', 10));
        $this->timeout = max(1, (int) config('services.ai_document_service.timeout', 120));
    }

    /**
     * Generate paket prompt dan simpan riwayat milik user.
     *
     * @param  array<string, mixed>  $input
     */
    public function generate(User $user, array $input): GeneratedPrompt
    {
        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();

        $idea = trim((string) ($input['idea'] ?? ''));
        if ($idea === '') {
            throw new RuntimeException('Ide prompt wajib diisi.');
        }
        $idea = Str::limit($idea, self::IDEA_MAX_LENGTH, '');

        $platform = $this->normalizePlatform((string) ($input['platform'] ?? 'generic'));
        $promptType = $this->normalizePromptType((string) ($input['prompt_type'] ?? 'image'));
        $contextNotes = Str::limit(trim((string) ($input['context_notes'] ?? '')), self::CONTEXT_NOTES_MAX_LENGTH, '');

        // Hanya dokumen milik user + ready yang boleh jadi sumber.
        $sourceDocumentIds = $this->sanitizeSourceDocumentIds(
            (int) $user->id,
            $input['source_document_ids'] ?? []
        );
        $sourceContext = $this->buildSourceDocumentContext($user, $sourceDocumentIds);

        $referenceImage = $input['reference_image'] ?? null;
        $storedImage = $this->storeReferenceImage($user, $referenceImage);

        $containsInternalContext = $sourceDocumentIds !== []
            || $storedImage !== null
            || $contextNotes !== '';

        try {
            $package = $this->requestPackage(
                $idea,
                $platform,
                $promptType,
                $contextNotes,
                $sourceContext,
                $storedImage !== null,
            );
        } catch (Throwable $e) {
            $this->deleteStoredImage($storedImage['path'] ?? null);

            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_PROMPT_GENERATION,
                userId: (int) $user->id,
                metadata: [
                    'platform' => $platform,
                    'prompt_type' => $promptType,
                    'contains_internal_context' => $containsInternalContext,
                    'reason' => 'generate_request_failed',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'generate_request_failed',
            );

            throw $e;
        }

        try {
            $prompt = GeneratedPrompt::create([
                'user_id' => $user->id,
                'platform' => $platform,
                'platform_label' => self::PLATFORMS[$platform] ?? $package['platform_label'] ?? $platform,
                'prompt_type' => $promptType,
                'prompt_type_label' => self::PROMPT_TYPES[$promptType] ?? $package['prompt_type_label'] ?? $promptType,
                'title' => $this->deriveTitle($idea),
                'idea' => $idea,
                'package' => [
                    'main_prompt' => $package['main_prompt'],
                    'variants' => $package['variants'],
                    'negative_prompt' => $package['negative_prompt'],
                    'recommended_settings' => $package['recommended_settings'],
                    'notes_id' => $package['notes_id'],
                ],
                'source_document_ids' => $sourceDocumentIds,
                'contains_internal_context' => $containsInternalContext,
                'reference_image_path' => $storedImage['path'] ?? null,
                'reference_image_mime' => $storedImage['mime'] ?? null,
                'reference_image_size_bytes' => $storedImage['size'] ?? null,
                'model_label' => $package['model_label'] ?: null,
            ]);
        } catch (Throwable $e) {
            $this->deleteStoredImage($storedImage['path'] ?? null);

            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_PROMPT_GENERATION,
                userId: (int) $user->id,
                metadata: [
                    'platform' => $platform,
                    'prompt_type' => $promptType,
                    'contains_internal_context' => $containsInternalContext,
                    'reason' => 'persist_failed',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'persist_failed',
            );

            throw $e;
        }

        $usageEvents->completed(
            feature: AIUsageEvent::FEATURE_PROMPT_GENERATION,
            userId: (int) $user->id,
            metadata: [
                'generated_prompt_id' => (int) $prompt->id,
                'platform' => $platform,
                'prompt_type' => $promptType,
                'document_count' => count($sourceDocumentIds),
                'has_reference_image' => $storedImage !== null,
                'contains_internal_context' => $containsInternalContext,
                ...$usageEvents->modelMetadata($package['model_label'] ?: null),
            ],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $prompt,
        );

        return $prompt;
    }

    public function deletePrompt(GeneratedPrompt $prompt): void
    {
        $this->deleteStoredImage($prompt->reference_image_path);
        $prompt->delete();
    }

    public function normalizePlatform(string $platform): string
    {
        $key = strtolower(trim($platform));
        $key = str_replace([' ', '-'], '_', $key);

        return array_key_exists($key, self::PLATFORMS) ? $key : 'generic';
    }

    public function normalizePromptType(string $promptType): string
    {
        $key = strtolower(trim($promptType));
        $key = str_replace([' ', '-'], '_', $key);

        return array_key_exists($key, self::PROMPT_TYPES) ? $key : 'image';
    }

    /**
     * @param  mixed  $documentIds
     * @return list<int>
     */
    protected function sanitizeSourceDocumentIds(int $userId, mixed $documentIds): array
    {
        $requested = collect(is_iterable($documentIds) ? $documentIds : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($requested->isEmpty()) {
            return [];
        }

        return Document::query()
            ->where('user_id', $userId)
            ->where('status', 'ready')
            ->whereIn('id', $requested->all())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string, platform_label: string, prompt_type_label: string, model_label: string}
     */
    protected function requestPackage(
        string $idea,
        string $platform,
        string $promptType,
        string $contextNotes,
        string $sourceContext = '',
        bool $hasReferenceImage = false,
    ): array
    {
        $payload = [
            'idea' => $idea,
            'platform' => $platform,
            'prompt_type' => $promptType,
            'context_notes' => $contextNotes !== '' ? $contextNotes : null,
            'source_context' => $sourceContext !== '' ? $sourceContext : null,
            'has_reference_image' => $hasReferenceImage,
        ];

        $response = Http::withToken($this->token ?: '')
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/api/prompts/generate', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->body() ?: 'Gagal membuat paket prompt.');
        }

        $data = $response->json();
        if (! is_array($data) || ! is_string($data['main_prompt'] ?? null) || trim($data['main_prompt']) === '') {
            throw new RuntimeException('Hasil paket prompt tidak valid.');
        }

        return [
            'main_prompt' => (string) $data['main_prompt'],
            'variants' => array_values(array_filter(
                is_array($data['variants'] ?? null) ? $data['variants'] : [],
                fn ($v) => is_string($v) && trim($v) !== '',
            )),
            'negative_prompt' => (string) ($data['negative_prompt'] ?? ''),
            'recommended_settings' => is_array($data['recommended_settings'] ?? null) ? $data['recommended_settings'] : [],
            'notes_id' => (string) ($data['notes_id'] ?? ''),
            'platform_label' => (string) ($data['platform_label'] ?? ''),
            'prompt_type_label' => (string) ($data['prompt_type_label'] ?? ''),
            'model_label' => (string) ($data['model_label'] ?? ''),
        ];
    }

    /**
     * Build a bounded text context from indexed chunks of owned, ready documents.
     *
     * @param  list<int>  $sourceDocumentIds
     */
    protected function buildSourceDocumentContext(User $user, array $sourceDocumentIds): string
    {
        if ($sourceDocumentIds === []) {
            return '';
        }

        $orderedIds = array_values(array_unique(array_map('intval', $sourceDocumentIds)));
        $orderedIds = array_slice($orderedIds, 0, self::SOURCE_DOCUMENT_CONTEXT_DOCUMENT_LIMIT);

        $documents = Document::where('user_id', $user->id)
            ->where('status', 'ready')
            ->whereIn('id', $orderedIds)
            ->get(['id', 'original_name'])
            ->keyBy('id');

        $sections = [];
        foreach ($orderedIds as $documentId) {
            $document = $documents->get($documentId);
            if (! $document) {
                continue;
            }

            $chunks = $document->chunks()
                ->whereNotNull('text_content')
                ->orderBy('page_number')
                ->orderBy('id')
                ->limit(self::SOURCE_DOCUMENT_CONTEXT_CHUNK_LIMIT)
                ->get(['page_number', 'text_content']);

            $lines = ['Dokumen: '.trim((string) $document->original_name)];
            foreach ($chunks as $chunk) {
                $text = $this->compactContextText((string) $chunk->text_content);
                if ($text === '') {
                    continue;
                }

                $page = $chunk->page_number !== null ? 'Hal. '.(int) $chunk->page_number : 'Cuplikan';
                $lines[] = '- '.$page.': '.Str::limit($text, self::SOURCE_DOCUMENT_CONTEXT_CHUNK_MAX_LENGTH, '');
            }

            if (count($lines) === 1) {
                $lines[] = '- Tidak ada cuplikan teks indeks yang tersedia.';
            }

            $sections[] = implode("\n", $lines);
        }

        return Str::limit(implode("\n\n", $sections), self::SOURCE_DOCUMENT_CONTEXT_MAX_LENGTH, '');
    }

    protected function compactContextText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Validasi tipe/ukuran lalu simpan reference image ke private disk.
     *
     * @return array{path: string, mime: string, size: int}|null
     */
    protected function storeReferenceImage(User $user, mixed $file): ?array
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::REFERENCE_IMAGE_MIME_TYPES, true)) {
            throw new RuntimeException('Format gambar referensi tidak didukung. Gunakan JPG, PNG, atau WebP.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::REFERENCE_IMAGE_MAX_BYTES) {
            throw new RuntimeException('Ukuran gambar referensi maksimal 5 MB.');
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = 'prompt-references/'.$user->id.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
            throw new RuntimeException('Gagal menyimpan gambar referensi.');
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    protected function deleteStoredImage(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    protected function deriveTitle(string $idea): string
    {
        $title = GeneratedPrompt::compactDisplayTitle($idea);

        return $title !== '' ? $title : Str::limit(trim($idea), 64, '');
    }
}
