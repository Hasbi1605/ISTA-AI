<?php

namespace App\Services\Prompts;

use App\Models\AIUsageEvent;
use App\Models\GeneratedPrompt;
use App\Models\GeneratedPromptVersion;
use App\Models\User;
use App\Services\Admin\AIUsageEventService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        'generic' => 'Universal',
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

    public const REVISION_INSTRUCTION_MAX_LENGTH = 3000;

    /** Reference image privat (MVP): tipe & ukuran yang diizinkan. */
    public const REFERENCE_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
    ];

    public const REFERENCE_IMAGE_MAX_BYTES = 5_242_880; // 5 MB

    public const REFERENCE_IMAGE_MAX_COUNT = 5;

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

        $referenceImages = $input['reference_images'] ?? ($input['reference_image'] ?? null);
        $storedImages = $this->storeReferenceImages($user, $referenceImages);
        $referenceImagePayloads = $this->buildReferenceImagePayloads($storedImages);

        $containsInternalContext = $storedImages !== []
            || $contextNotes !== '';

        try {
            $package = $this->requestPackage(
                $idea,
                $platform,
                $promptType,
                $contextNotes,
                $referenceImagePayloads,
            );
        } catch (Throwable $e) {
            $this->deleteStoredImages($storedImages);

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
            $prompt = DB::transaction(function () use (
                $user,
                $platform,
                $promptType,
                $idea,
                $package,
                $containsInternalContext,
                $storedImages,
            ): GeneratedPrompt {
                $packageData = $this->packageData($package);
                $firstImage = $storedImages[0] ?? null;

                $prompt = GeneratedPrompt::create([
                    'user_id' => $user->id,
                    'platform' => $platform,
                    'platform_label' => self::PLATFORMS[$platform] ?? $package['platform_label'] ?? $platform,
                    'prompt_type' => $promptType,
                    'prompt_type_label' => self::PROMPT_TYPES[$promptType] ?? $package['prompt_type_label'] ?? $promptType,
                    'title' => $this->deriveTitle($idea),
                    'idea' => $idea,
                    'package' => $packageData,
                    'source_document_ids' => [],
                    'contains_internal_context' => $containsInternalContext,
                    'reference_image_path' => $firstImage['path'] ?? null,
                    'reference_image_mime' => $firstImage['mime'] ?? null,
                    'reference_image_size_bytes' => $firstImage['size'] ?? null,
                    'model_label' => $package['model_label'] ?: null,
                ]);

                $version = $prompt->versions()->create([
                    'version_number' => 1,
                    'package' => $packageData,
                    'revision_instruction' => null,
                    'reference_images' => $this->referenceImageMetadata($storedImages),
                    'model_label' => $package['model_label'] ?: null,
                ]);

                $prompt->forceFill(['current_version_id' => $version->id])->save();

                return $prompt->fresh(['currentVersion', 'versions']) ?? $prompt;
            });
        } catch (Throwable $e) {
            $this->deleteStoredImages($storedImages);

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
                'has_reference_image' => $storedImages !== [],
                'reference_image_count' => count($storedImages),
                'reference_image_analyzed' => (bool) ($package['reference_image_analyzed'] ?? false),
                'contains_internal_context' => $containsInternalContext,
                ...$usageEvents->modelMetadata($package['model_label'] ?: null),
            ],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $prompt,
        );

        return $prompt;
    }

    public function revise(
        User $user,
        GeneratedPrompt $prompt,
        string $instruction,
        ?GeneratedPromptVersion $baseVersion = null,
    ): GeneratedPrompt {
        if (! $prompt->isOwnedBy($user)) {
            throw new RuntimeException('Prompt tidak ditemukan.');
        }

        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();

        $revisionInstruction = Str::limit(trim($instruction), self::REVISION_INSTRUCTION_MAX_LENGTH, '');
        if ($revisionInstruction === '') {
            throw new RuntimeException('Instruksi revisi wajib diisi.');
        }

        $prompt->loadMissing(['currentVersion', 'versions']);
        $baseVersion = $this->resolveBaseVersion($prompt, $baseVersion);
        $basePackage = $baseVersion?->normalizedPackage() ?? $prompt->normalizedPackage();
        $storedImages = $this->storedImagesForVersion($prompt, $baseVersion);
        $referenceImagePayloads = $this->buildReferenceImagePayloads($storedImages);

        try {
            $package = $this->requestPackage(
                (string) $prompt->idea,
                (string) $prompt->platform,
                (string) $prompt->prompt_type,
                '',
                $referenceImagePayloads,
                $basePackage,
                $revisionInstruction,
            );
        } catch (Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_PROMPT_GENERATION,
                userId: (int) $user->id,
                metadata: [
                    'generated_prompt_id' => (int) $prompt->id,
                    'platform' => (string) $prompt->platform,
                    'prompt_type' => (string) $prompt->prompt_type,
                    'revision' => true,
                    'reason' => 'revision_request_failed',
                ],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'revision_request_failed',
                subject: $prompt,
            );

            throw $e;
        }

        $updatedPrompt = DB::transaction(function () use ($prompt, $package, $revisionInstruction, $storedImages): GeneratedPrompt {
            $packageData = $this->packageData($package);
            $nextVersionNumber = ((int) $prompt->versions()->max('version_number')) + 1;
            $firstImage = $storedImages[0] ?? null;

            $version = $prompt->versions()->create([
                'version_number' => $nextVersionNumber,
                'package' => $packageData,
                'revision_instruction' => $revisionInstruction,
                'reference_images' => $this->referenceImageMetadata($storedImages),
                'model_label' => $package['model_label'] ?: null,
            ]);

            $prompt->forceFill([
                'package' => $packageData,
                'current_version_id' => $version->id,
                'reference_image_path' => $firstImage['path'] ?? null,
                'reference_image_mime' => $firstImage['mime'] ?? null,
                'reference_image_size_bytes' => $firstImage['size'] ?? null,
                'model_label' => $package['model_label'] ?: null,
            ])->save();

            return $prompt->fresh(['currentVersion', 'versions']) ?? $prompt;
        });

        $usageEvents->completed(
            feature: AIUsageEvent::FEATURE_PROMPT_GENERATION,
            userId: (int) $user->id,
            metadata: [
                'generated_prompt_id' => (int) $updatedPrompt->id,
                'platform' => (string) $updatedPrompt->platform,
                'prompt_type' => (string) $updatedPrompt->prompt_type,
                'revision' => true,
                'version_number' => (int) ($updatedPrompt->currentVersion?->version_number ?? 0),
                'has_reference_image' => $storedImages !== [],
                'reference_image_count' => count($storedImages),
                'reference_image_analyzed' => (bool) ($package['reference_image_analyzed'] ?? false),
                'contains_internal_context' => (bool) $updatedPrompt->contains_internal_context,
                ...$usageEvents->modelMetadata($package['model_label'] ?: null),
            ],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $updatedPrompt,
        );

        return $updatedPrompt;
    }

    public function deletePrompt(GeneratedPrompt $prompt): void
    {
        $this->deleteStoredImagePaths($this->referenceImagePaths($prompt));
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
     * @param  list<array{label: string, mime_type: string, data_base64: string}>  $referenceImagePayloads
     * @param  array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string}|null  $currentPackage
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string, platform_label: string, prompt_type_label: string, model_label: string, reference_image_analyzed: bool}
     */
    protected function requestPackage(
        string $idea,
        string $platform,
        string $promptType,
        string $contextNotes,
        array $referenceImagePayloads = [],
        ?array $currentPackage = null,
        string $revisionInstruction = '',
    ): array
    {
        $payload = [
            'idea' => $idea,
            'platform' => $platform,
            'prompt_type' => $promptType,
            'context_notes' => $contextNotes !== '' ? $contextNotes : null,
            'reference_images' => $referenceImagePayloads !== [] ? $referenceImagePayloads : null,
            'current_package' => $currentPackage,
            'revision_instruction' => $revisionInstruction !== '' ? $revisionInstruction : null,
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
            'reference_image_analyzed' => (bool) ($data['reference_image_analyzed'] ?? false),
        ];
    }

    /**
     * @param  list<array{path: string, mime: string, size: int, label: string}>  $storedImages
     * @return list<array{label: string, mime_type: string, data_base64: string}>
     */
    protected function buildReferenceImagePayloads(array $storedImages): array
    {
        $payloads = [];

        foreach ($storedImages as $index => $storedImage) {
            $path = $storedImage['path'] ?? null;
            if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
                throw new RuntimeException('Gambar referensi tidak ditemukan.');
            }

            $contents = Storage::disk('local')->get($path);
            if (! is_string($contents) || $contents === '') {
                throw new RuntimeException('Gambar referensi kosong.');
            }

            $payloads[] = [
                'label' => (string) ($storedImage['label'] ?? 'Gambar '.($index + 1)),
                'mime_type' => (string) $storedImage['mime'],
                'data_base64' => base64_encode($contents),
            ];
        }

        return $payloads;
    }

    /**
     * Validasi tipe/ukuran lalu simpan reference images ke private disk.
     *
     * @return list<array{path: string, mime: string, size: int, label: string}>
     */
    protected function storeReferenceImages(User $user, mixed $files): array
    {
        $files = $this->normalizeReferenceImageFiles($files);
        if ($files === []) {
            return [];
        }

        if (count($files) > self::REFERENCE_IMAGE_MAX_COUNT) {
            throw new RuntimeException('Gambar referensi maksimal 5 file.');
        }

        $stored = [];

        try {
            foreach ($files as $index => $file) {
                $stored[] = $this->storeReferenceImage($user, $file, 'Gambar '.($index + 1));
            }
        } catch (Throwable $e) {
            $this->deleteStoredImages($stored);
            throw $e;
        }

        return $stored;
    }

    /**
     * @return list<UploadedFile>
     */
    protected function normalizeReferenceImageFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
    }

    /**
     * @return array{path: string, mime: string, size: int, label: string}
     */
    protected function storeReferenceImage(User $user, UploadedFile $file, string $label): array
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::REFERENCE_IMAGE_MIME_TYPES, true)) {
            throw new RuntimeException('Format gambar referensi tidak didukung. Gunakan JPG atau PNG.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::REFERENCE_IMAGE_MAX_BYTES) {
            throw new RuntimeException('Ukuran gambar referensi maksimal 5 MB.');
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            default => 'jpg',
        };

        $path = 'prompt-references/'.$user->id.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
            throw new RuntimeException('Gagal menyimpan gambar referensi.');
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size, 'label' => $label];
    }

    /**
     * @param  list<array{path: string, mime: string, size: int, label: string}>  $storedImages
     * @return list<array{label: string, path: string, mime: string, size: int}>
     */
    protected function referenceImageMetadata(array $storedImages): array
    {
        return array_map(fn (array $image): array => [
            'label' => (string) $image['label'],
            'path' => (string) $image['path'],
            'mime' => (string) $image['mime'],
            'size' => (int) $image['size'],
        ], $storedImages);
    }

    /**
     * @return list<array{path: string, mime: string, size: int, label: string}>
     */
    protected function storedImagesForVersion(GeneratedPrompt $prompt, ?GeneratedPromptVersion $version): array
    {
        $images = is_array($version?->reference_images) ? $version->reference_images : [];
        $stored = [];

        foreach ($images as $index => $image) {
            if (! is_array($image) || empty($image['path']) || empty($image['mime'])) {
                continue;
            }

            $stored[] = [
                'label' => (string) ($image['label'] ?? 'Gambar '.($index + 1)),
                'path' => (string) $image['path'],
                'mime' => (string) $image['mime'],
                'size' => (int) ($image['size'] ?? 0),
            ];
        }

        if ($stored !== []) {
            return $stored;
        }

        if ($prompt->reference_image_path) {
            return [[
                'label' => 'Gambar 1',
                'path' => (string) $prompt->reference_image_path,
                'mime' => (string) $prompt->reference_image_mime,
                'size' => (int) $prompt->reference_image_size_bytes,
            ]];
        }

        return [];
    }

    protected function resolveBaseVersion(GeneratedPrompt $prompt, ?GeneratedPromptVersion $baseVersion): ?GeneratedPromptVersion
    {
        if ($baseVersion && (int) $baseVersion->generated_prompt_id === (int) $prompt->id) {
            return $baseVersion;
        }

        if ($prompt->currentVersion) {
            return $prompt->currentVersion;
        }

        return $prompt->versions()->orderByDesc('version_number')->first();
    }

    /**
     * @return list<string>
     */
    protected function referenceImagePaths(GeneratedPrompt $prompt): array
    {
        $paths = [];
        if ($prompt->reference_image_path) {
            $paths[] = (string) $prompt->reference_image_path;
        }

        $prompt->loadMissing('versions');
        foreach ($prompt->versions as $version) {
            $images = is_array($version->reference_images) ? $version->reference_images : [];
            foreach ($images as $image) {
                if (is_array($image) && ! empty($image['path'])) {
                    $paths[] = (string) $image['path'];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<array{path: string, mime: string, size: int, label: string}>  $storedImages
     */
    protected function deleteStoredImages(array $storedImages): void
    {
        $this->deleteStoredImagePaths(array_map(fn (array $image): string => (string) $image['path'], $storedImages));
    }

    /**
     * @param  list<string>  $paths
     */
    protected function deleteStoredImagePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path !== '') {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string}
     */
    protected function packageData(array $package): array
    {
        return [
            'main_prompt' => $package['main_prompt'],
            'variants' => $package['variants'],
            'negative_prompt' => $package['negative_prompt'],
            'recommended_settings' => $package['recommended_settings'],
            'notes_id' => $package['notes_id'],
        ];
    }

    protected function deriveTitle(string $idea): string
    {
        $title = GeneratedPrompt::compactDisplayTitle($idea);

        return $title !== '' ? $title : Str::limit(trim($idea), 64, '');
    }
}
