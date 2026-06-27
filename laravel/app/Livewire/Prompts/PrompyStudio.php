<?php

namespace App\Livewire\Prompts;

use App\Models\GeneratedPrompt;
use App\Models\GeneratedPromptVersion;
use App\Services\Prompts\PromptStudioService;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Prompy Studio.
 *
 * Generator paket prompt profesional untuk platform AI eksternal, plus riwayat
 * private milik user.
 */
class PrompyStudio extends Component
{
    use WithFileUploads;

    private const HISTORY_LOAD_LIMIT = 50;

    public string $idea = '';

    public ?string $platform = null;

    public ?string $promptType = null;

    public string $contextNotes = '';

    /** Reference images privat yang dianalisis model vision. */
    public array $referenceImages = [];

    /** Dokumen acuan privat yang diringkas menjadi konteks prompt. */
    public array $referenceDocuments = [];

    /** Gambar baru yang dilampirkan dari composer revisi. */
    public array $revisionReferenceImages = [];

    /** Dokumen acuan baru yang dilampirkan dari composer revisi. */
    public array $revisionReferenceDocuments = [];

    /** Indices of active-version reference images excluded by user (not carried to next revision). */
    public array $excludedActiveReferenceImages = [];

    public ?int $activePromptId = null;

    public ?int $activePromptVersionId = null;

    public string $revisionInstruction = '';

    public array $promptChatMessages = [];

    public bool $isGenerating = false;

    public bool $showPromptConfiguration = true;

    public bool $isComposingNewPrompt = false;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $latestPrompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($latestPrompt) {
            $this->activatePromptModel($latestPrompt);
        }
    }

    public function selectPlatform(string $key): void
    {
        if (array_key_exists($key, PromptStudioService::PLATFORMS)) {
            $this->platform = $key;
        }
    }

    public function selectPromptType(string $key): void
    {
        if (array_key_exists($key, PromptStudioService::PROMPT_TYPES)) {
            $this->promptType = $key;
        }
    }

    public function selectPrompt(int $promptId): void
    {
        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $promptId)
            ->where('user_id', Auth::id())
            ->first();

        if ($prompt) {
            $this->activatePromptModel($prompt);
            $this->isComposingNewPrompt = false;
            $this->revisionInstruction = '';
            $this->excludedActiveReferenceImages = [];
            $this->showPromptConfiguration = false;
            $this->clearReferenceDocuments();
            $this->clearRevisionReferenceImages();
            $this->clearRevisionReferenceDocuments();
        }
    }

    public function selectPromptVersion(int $versionId): void
    {
        $prompt = GeneratedPrompt::query()
            ->where('user_id', Auth::id())
            ->whereHas('versions', fn ($query) => $query->where('id', $versionId))
            ->with(['versions' => fn ($query) => $query->orderBy('version_number')])
            ->first();

        if (! $prompt) {
            $this->isGenerating = false;

            return;
        }

        $this->activePromptId = $prompt->id;
        $this->activePromptVersionId = $versionId;
        $this->isComposingNewPrompt = false;
        $this->showPromptConfiguration = false;
        $this->revisionInstruction = '';
        $this->excludedActiveReferenceImages = [];
        $this->applyPromptConfiguration($prompt);
        $this->promptChatMessages = $this->buildPromptChatMessages($prompt);
        $this->clearReferenceDocuments();
        $this->clearRevisionReferenceImages();
        $this->clearRevisionReferenceDocuments();
    }

    public function startNewPrompt(): void
    {
        $this->idea = '';
        $this->platform = null;
        $this->promptType = null;
        $this->contextNotes = '';
        $this->referenceImages = [];
        $this->referenceDocuments = [];
        $this->revisionReferenceImages = [];
        $this->revisionReferenceDocuments = [];
        $this->excludedActiveReferenceImages = [];
        $this->activePromptId = null;
        $this->activePromptVersionId = null;
        $this->revisionInstruction = '';
        $this->promptChatMessages = [];
        $this->isGenerating = false;
        $this->showPromptConfiguration = true;
        $this->isComposingNewPrompt = true;
        $this->statusMessage = null;
        $this->resetValidation();
        $this->dispatch('prompy-reference-image-cleared');
        $this->dispatch('prompy-reference-document-cleared');
        $this->dispatch('prompy-revision-reference-image-cleared');
        $this->dispatch('prompy-revision-reference-document-cleared');
    }

    public function generate(PromptStudioService $service): void
    {
        if ($this->activePromptId && ! $this->isComposingNewPrompt) {
            $this->generateConfiguredRevision($service);

            return;
        }

        $this->generateConfiguredPrompt($service);
    }

    public function generateConfiguredPrompt(PromptStudioService $service): void
    {
        $this->statusMessage = null;

        $this->validatePromptConfiguration();

        $this->enforceRateLimit('generatePrompt', 10, 60, 'Terlalu banyak generate prompt. Coba lagi sebentar.');
        $this->isGenerating = true;

        try {
            $prompt = $service->generate(Auth::user(), [
                'idea' => $this->idea,
                'platform' => $this->platform,
                'prompt_type' => $this->promptType,
                'context_notes' => $this->contextNotes,
                'reference_images' => $this->referenceImages,
                'reference_documents' => $this->referenceDocuments,
            ]);

            $this->activatePromptModel($prompt);
            $this->isComposingNewPrompt = false;
            $this->revisionInstruction = '';
            $this->showPromptConfiguration = false;
            $this->reset('referenceImages');
            $this->clearReferenceDocuments();
            $this->clearRevisionReferenceImages();
            $this->clearRevisionReferenceDocuments();
            $this->dispatch('prompy-reference-image-cleared');
            $this->statusMessage = 'Paket prompt berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal membuat paket prompt. Coba lagi sebentar.');
        } finally {
            $this->isGenerating = false;
        }
    }

    public function revisePrompt(PromptStudioService $service): void
    {
        $this->statusMessage = null;

        $this->validate([
            'revisionInstruction' => ['required', 'string', 'max:'.PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH],
        ], [
            'revisionInstruction.required' => 'Instruksi revisi wajib diisi.',
            'revisionInstruction.max' => 'Instruksi revisi maksimal 8000 karakter.',
        ]);

        $this->enforceRateLimit('revisePrompt', 10, 60, 'Terlalu banyak revisi prompt. Coba lagi sebentar.');
        $this->isGenerating = true;

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $this->activePromptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            $this->isGenerating = false;

            return;
        }

        $baseVersion = $this->activePromptVersionId
            ? $prompt->versions->firstWhere('id', (int) $this->activePromptVersionId)
            : $prompt->currentVersion;

        try {
            $overrides = $this->buildExcludedImageOverrides($baseVersion);
            $updated = $service->revise(Auth::user(), $prompt, $this->revisionInstruction, $baseVersion, $overrides);

            $this->activatePromptModel($updated);
            $this->revisionInstruction = '';
            $this->excludedActiveReferenceImages = [];
            $this->isComposingNewPrompt = false;
            $this->showPromptConfiguration = false;
            $this->statusMessage = 'Revisi prompt berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal merevisi prompt. Coba lagi sebentar.');
        } finally {
            $this->isGenerating = false;
        }
    }

    public function sendPromptRevision(?string $message = null): void
    {
        $this->sendPromptChat($message);
    }

    public function sendPromptChat(?string $message = null): void
    {
        if ($message !== null) {
            $this->revisionInstruction = $message;
        }

        $this->statusMessage = null;

        $this->validatePromptChatInput();

        $userMessage = trim($this->revisionInstruction);
        $this->revisionInstruction = '';

        if ($userMessage === '') {
            return;
        }

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $this->activePromptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            return;
        }

        $this->enforceRateLimit('promptChat', 30, 60, 'Terlalu banyak pesan prompt. Coba lagi sebentar.');

        $service = app(PromptStudioService::class);

        if ($this->revisionReferenceImages !== [] || $this->revisionReferenceDocuments !== []) {
            $this->revisePromptWithReferenceAttachments($service, $prompt, $userMessage);

            return;
        }

        try {
            $decision = $service->chat(Auth::user(), $prompt, $userMessage, $this->promptChatMessages);
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal memproses chat Prompy. Coba lagi sebentar.');

            return;
        }

        if (($decision['intent'] ?? 'answer') === 'revise') {
            $this->revisionInstruction = trim((string) ($decision['revision_instruction'] ?? '')) ?: $userMessage;
            $this->revisePrompt($service);

            return;
        }

        $updated = $this->persistPromptChatExchange($prompt, [
            $this->makePromptChatMessage('user', $userMessage),
            $this->makePromptChatMessage('assistant', (string) ($decision['assistant_message'] ?? 'Saya bantu bahas prompt ini.')),
        ]);

        if ($updated) {
            $this->activatePromptModel($updated);
            $this->isComposingNewPrompt = false;
            $this->showPromptConfiguration = false;
        }
    }

    public function generateConfiguredRevision(PromptStudioService $service): void
    {
        $this->statusMessage = null;
        $this->validatePromptConfiguration();
        $this->enforceRateLimit('generateConfiguredPrompt', 10, 60, 'Terlalu banyak generate prompt. Coba lagi sebentar.');
        $this->isGenerating = true;

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $this->activePromptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            $this->isGenerating = false;

            return;
        }

        $baseVersion = $this->activePromptVersionId
            ? $prompt->versions->firstWhere('id', (int) $this->activePromptVersionId)
            : $prompt->currentVersion;

        $instruction = 'Generate ulang prompt aktif dari konfigurasi terbaru. Gunakan ide, platform, jenis keluaran, gambar referensi, dan dokumen acuan terbaru dari panel konfigurasi sebagai acuan utama.';
        $overrides = [
            'idea' => $this->idea,
            'platform' => $this->platform,
            'prompt_type' => $this->promptType,
            'context_notes' => $this->contextNotes,
        ];

        if ($this->referenceImages !== []) {
            $overrides['reference_images'] = $this->referenceImages;
        }

        if ($this->referenceDocuments !== []) {
            $overrides['reference_documents'] = $this->referenceDocuments;
        }

        try {
            $updated = $service->revise(Auth::user(), $prompt, $instruction, $baseVersion, $overrides);

            $this->activatePromptModel($updated);
            $this->revisionInstruction = '';
            $this->isComposingNewPrompt = false;
            $this->showPromptConfiguration = false;
            $this->reset('referenceImages');
            $this->clearReferenceDocuments();
            $this->clearRevisionReferenceImages();
            $this->clearRevisionReferenceDocuments();
            $this->dispatch('prompy-reference-image-cleared');
            $this->statusMessage = 'Prompt berhasil dibuat ulang sebagai versi baru.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal membuat ulang prompt. Coba lagi sebentar.');
        } finally {
            $this->isGenerating = false;
        }
    }

    public function deletePrompt(int $promptId, PromptStudioService $service): void
    {
        $prompt = GeneratedPrompt::where('id', $promptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            return;
        }

        $service->deletePrompt($prompt);

        if ((int) $this->activePromptId === (int) $promptId) {
            $this->activePromptId = null;
            $this->activePromptVersionId = null;
            $this->isComposingNewPrompt = true;
            $this->revisionInstruction = '';
            $this->referenceDocuments = [];
            $this->revisionReferenceImages = [];
            $this->revisionReferenceDocuments = [];
            $this->promptChatMessages = [];
            $this->isGenerating = false;
            $this->showPromptConfiguration = true;
            $this->dispatch('prompy-reference-document-cleared');
            $this->dispatch('prompy-revision-reference-image-cleared');
            $this->dispatch('prompy-revision-reference-document-cleared');
        }
    }

    public function clearRevisionReferenceImages(): void
    {
        $this->reset('revisionReferenceImages');
        $this->resetValidation('revisionReferenceImages');
        $this->dispatch('prompy-revision-reference-image-cleared');
    }

    public function clearRevisionReferenceDocuments(): void
    {
        $this->reset('revisionReferenceDocuments');
        $this->resetValidation('revisionReferenceDocuments');
        $this->dispatch('prompy-revision-reference-document-cleared');
    }

    public function clearReferenceDocuments(): void
    {
        $this->reset('referenceDocuments');
        $this->resetValidation('referenceDocuments');
        $this->dispatch('prompy-reference-document-cleared');
    }

    public function render()
    {
        $userId = (int) Auth::id();

        $prompts = GeneratedPrompt::with(['currentVersion', 'versions' => fn ($query) => $query->orderBy('version_number')])
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LOAD_LIMIT)
            ->get();

        $activePrompt = null;
        if (! $this->isComposingNewPrompt) {
            $activePrompt = $this->activePromptId
                ? $prompts->firstWhere('id', (int) $this->activePromptId)
                : null;

            if (! $activePrompt) {
                $activePrompt = $prompts->first();

                if ($activePrompt) {
                    $this->activatePromptModel($activePrompt);
                }
            }
        }

        $activeVersion = null;
        $activeVersionId = null;
        if ($activePrompt) {
            $activeVersion = $this->activePromptVersionId
                ? $activePrompt->versions->firstWhere('id', (int) $this->activePromptVersionId)
                : null;
            $activeVersion ??= $activePrompt->currentVersion
                ?: $activePrompt->versions->sortByDesc('version_number')->first();
            $activeVersionId = $activeVersion ? (int) $activeVersion->id : null;
        }

        return view('livewire.prompts.prompy-studio', [
            'prompts' => $prompts,
            'activePrompt' => $activePrompt,
            'activeVersion' => $activeVersion,
            'activeVersionId' => $activeVersionId,
            'activePackage' => $activeVersion?->normalizedPackage() ?? $activePrompt?->normalizedPackage(),
            'platforms' => PromptStudioService::PLATFORMS,
            'promptTypes' => PromptStudioService::PROMPT_TYPES,
        ]);
    }

    private function activatePromptModel(GeneratedPrompt $prompt): void
    {
        $prompt->loadMissing(['currentVersion', 'versions']);
        $this->activePromptId = (int) $prompt->id;
        $this->activePromptVersionId = $prompt->current_version_id
            ? (int) $prompt->current_version_id
            : null;
        $this->showPromptConfiguration = false;
        $this->applyPromptConfiguration($prompt);
        $this->promptChatMessages = $this->buildPromptChatMessages($prompt);
    }

    private function applyPromptConfiguration(GeneratedPrompt $prompt): void
    {
        $this->idea = (string) $prompt->idea;
        $this->platform = (string) $prompt->platform;
        if (! array_key_exists($this->platform, PromptStudioService::PLATFORMS)) {
            $this->platform = app(PromptStudioService::class)->normalizePlatform($this->platform);
        }
        $this->promptType = (string) $prompt->prompt_type;
        $this->contextNotes = '';
    }

    private function validatePromptConfiguration(): void
    {
        $this->validate([
            'idea' => ['required', 'string', 'max:'.PromptStudioService::IDEA_MAX_LENGTH],
            'platform' => ['required', 'string', 'in:'.implode(',', array_keys(PromptStudioService::PLATFORMS))],
            'promptType' => ['required', 'string', 'in:'.implode(',', array_keys(PromptStudioService::PROMPT_TYPES))],
            'contextNotes' => ['nullable', 'string', 'max:'.PromptStudioService::CONTEXT_NOTES_MAX_LENGTH],
            'referenceImages' => ['array', 'max:'.PromptStudioService::REFERENCE_IMAGE_MAX_COUNT],
            'referenceImages.*' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.(int) (PromptStudioService::REFERENCE_IMAGE_MAX_BYTES / 1024),
            ],
            'referenceDocuments' => ['array', 'max:'.PromptStudioService::REFERENCE_DOCUMENT_MAX_COUNT],
            'referenceDocuments.*' => [
                'nullable',
                'file',
                'mimes:pdf,docx,xlsx,csv',
                'max:'.(int) (PromptStudioService::REFERENCE_DOCUMENT_MAX_BYTES / 1024),
            ],
        ], [
            'idea.required' => 'Ide prompt wajib diisi.',
            'platform.required' => 'Pilih platform tujuan.',
            'platform.in' => 'Platform tidak dikenal.',
            'promptType.required' => 'Pilih jenis keluaran.',
            'promptType.in' => 'Jenis prompt tidak dikenal.',
            'referenceImages.max' => 'Gambar referensi maksimal 5 file.',
            'referenceImages.*.image' => 'Gambar referensi harus berupa file gambar.',
            'referenceImages.*.mimes' => 'Gambar referensi harus JPG atau PNG.',
            'referenceImages.*.max' => 'Ukuran tiap gambar referensi maksimal 5 MB.',
            'referenceDocuments.max' => 'Dokumen acuan maksimal 3 file.',
            'referenceDocuments.*.file' => 'Dokumen acuan harus berupa file.',
            'referenceDocuments.*.mimes' => 'Dokumen acuan harus PDF, DOCX, XLSX, atau CSV.',
            'referenceDocuments.*.max' => 'Ukuran tiap dokumen acuan maksimal 10 MB.',
        ]);
    }

    private function validatePromptChatInput(): void
    {
        $this->validate([
            'revisionInstruction' => ['required', 'string', 'max:'.PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH],
            'revisionReferenceImages' => ['array', 'max:'.PromptStudioService::REFERENCE_IMAGE_MAX_COUNT],
            'revisionReferenceImages.*' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.(int) (PromptStudioService::REFERENCE_IMAGE_MAX_BYTES / 1024),
            ],
            'revisionReferenceDocuments' => ['array', 'max:'.PromptStudioService::REFERENCE_DOCUMENT_MAX_COUNT],
            'revisionReferenceDocuments.*' => [
                'nullable',
                'file',
                'mimes:pdf,docx,xlsx,csv',
                'max:'.(int) (PromptStudioService::REFERENCE_DOCUMENT_MAX_BYTES / 1024),
            ],
        ], [
            'revisionInstruction.required' => 'Pesan wajib diisi.',
            'revisionInstruction.max' => 'Pesan maksimal 8000 karakter.',
            'revisionReferenceImages.max' => 'Gambar referensi maksimal 5 file.',
            'revisionReferenceImages.*.image' => 'Gambar revisi harus berupa file gambar.',
            'revisionReferenceImages.*.mimes' => 'Gambar revisi harus JPG atau PNG.',
            'revisionReferenceImages.*.max' => 'Ukuran tiap gambar revisi maksimal 5 MB.',
            'revisionReferenceDocuments.max' => 'Dokumen acuan revisi maksimal 3 file.',
            'revisionReferenceDocuments.*.file' => 'Dokumen revisi harus berupa file.',
            'revisionReferenceDocuments.*.mimes' => 'Dokumen revisi harus PDF, DOCX, XLSX, atau CSV.',
            'revisionReferenceDocuments.*.max' => 'Ukuran tiap dokumen revisi maksimal 10 MB.',
        ]);
    }

    private function revisePromptWithReferenceAttachments(PromptStudioService $service, GeneratedPrompt $prompt, string $instruction): void
    {
        $this->enforceRateLimit('revisePrompt', 10, 60, 'Terlalu banyak revisi prompt. Coba lagi sebentar.');
        $this->isGenerating = true;

        $baseVersion = $this->activePromptVersionId
            ? $prompt->versions->firstWhere('id', (int) $this->activePromptVersionId)
            : $prompt->currentVersion;
        $overrides = [];

        if ($this->revisionReferenceImages !== []) {
            $overrides['reference_images'] = $this->revisionReferenceImages;
        }

        if ($this->revisionReferenceDocuments !== []) {
            $overrides['reference_documents'] = $this->revisionReferenceDocuments;
        }

        try {
            $updated = $service->revise(Auth::user(), $prompt, $instruction, $baseVersion, $overrides);

            $this->activatePromptModel($updated);
            $this->revisionInstruction = '';
            $this->isComposingNewPrompt = false;
            $this->showPromptConfiguration = false;
            $this->clearRevisionReferenceImages();
            $this->clearRevisionReferenceDocuments();
            $this->statusMessage = 'Revisi prompt dengan lampiran baru berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal merevisi prompt dengan lampiran baru. Coba lagi sebentar.');
        } finally {
            $this->isGenerating = false;
        }
    }

    private function buildPromptChatMessages(GeneratedPrompt $prompt): array
    {
        $versions = $prompt->versions->sortBy('version_number')->values();
        $firstVersion = $versions->first();
        $messages = [
            [
                'role' => 'assistant',
                'content' => 'Lengkapi konfigurasi prompt. Setelah paket prompt dibuat, kolom revisi akan aktif di panel yang sama.',
                'timestamp' => $this->formatPromptTimestamp($firstVersion?->created_at ?? $prompt->created_at),
            ],
            [
                'role' => 'user',
                'content' => $this->promptConfigurationSummary($prompt, $firstVersion),
                'timestamp' => $this->formatPromptTimestamp($prompt->created_at),
            ],
        ];

        if ($firstVersion) {
            $messages[] = [
                'role' => 'assistant',
                'content' => 'Prompt "'.$prompt->displayTitle().'" berhasil dibuat sebagai Versi '.$firstVersion->version_number.'. Anda bisa meminta revisi spesifik di sini.',
                'timestamp' => $this->formatPromptTimestamp($firstVersion->created_at),
            ];
        }

        foreach ($versions as $version) {
            if (! $version->revision_instruction) {
                continue;
            }

            $messages[] = [
                'role' => 'user',
                'content' => (string) $version->revision_instruction,
                'timestamp' => $this->formatPromptTimestamp($version->created_at),
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->promptRevisionAssistantReply($version),
                'timestamp' => $this->formatPromptTimestamp($version->created_at),
            ];
        }

        return $this->appendPromptChatMessages(
            $messages,
            $this->normalizeStoredPromptChatMessages($prompt->chat_messages ?? []),
        );
    }

    private function persistPromptChatExchange(GeneratedPrompt $prompt, array $messages): ?GeneratedPrompt
    {
        return DB::transaction(function () use ($prompt, $messages): ?GeneratedPrompt {
            $lockedPrompt = GeneratedPrompt::with(['currentVersion', 'versions'])
                ->lockForUpdate()
                ->where('id', $prompt->id)
                ->where('user_id', Auth::id())
                ->first();

            if (! $lockedPrompt) {
                return null;
            }

            $mergedMessages = $this->appendPromptChatMessages(
                $this->normalizeStoredPromptChatMessages($lockedPrompt->chat_messages ?? []),
                $messages,
            );

            $lockedPrompt->forceFill(['chat_messages' => $mergedMessages])->save();

            return $lockedPrompt->fresh(['currentVersion', 'versions']);
        });
    }

    private function promptRevisionAssistantReply(GeneratedPromptVersion $version): string
    {
        $versionLabel = 'Versi '.(int) $version->version_number;
        $instruction = mb_strtolower(trim((string) $version->revision_instruction), 'UTF-8');

        if (str_contains($instruction, 'konfigurasi terbaru') || str_contains($instruction, 'generate ulang prompt aktif')) {
            return 'Saya sudah membuat ulang prompt dari konfigurasi terbaru sebagai '.$versionLabel.'. Panel hasil kanan sudah memakai versi ini dan siap disalin.';
        }

        return 'Sudah saya terapkan ke '.$versionLabel.'. Panel hasil kanan sudah diperbarui dan siap disalin.';
    }

    private function makePromptChatMessage(string $role, string $content): array
    {
        return [
            'role' => $role === 'user' ? 'user' : 'assistant',
            'content' => trim($content),
            'timestamp' => now()->format('H:i'),
        ];
    }

    private function normalizeStoredPromptChatMessages(array $messages): array
    {
        return collect($messages)
            ->filter(fn ($message) => is_array($message))
            ->map(fn (array $message) => [
                'role' => ($message['role'] ?? null) === 'user' ? 'user' : 'assistant',
                'content' => trim((string) ($message['content'] ?? '')),
                'timestamp' => (string) ($message['timestamp'] ?? now()->format('H:i')),
            ])
            ->filter(fn (array $message) => $message['content'] !== '')
            ->values()
            ->all();
    }

    private function appendPromptChatMessages(array ...$messageGroups): array
    {
        $merged = [];

        foreach ($messageGroups as $messages) {
            $merged = [
                ...$merged,
                ...$this->normalizeStoredPromptChatMessages($messages),
            ];
        }

        return $merged;
    }

    private function promptConfigurationSummary(?GeneratedPrompt $prompt = null, ?GeneratedPromptVersion $version = null): string
    {
        $platformLabel = $prompt?->platform_label
            ?: (PromptStudioService::PLATFORMS[$this->platform ?? ''] ?? 'Belum dipilih');
        $promptTypeLabel = $prompt?->prompt_type_label
            ?: (PromptStudioService::PROMPT_TYPES[$this->promptType ?? ''] ?? 'Belum dipilih');
        $imageCount = is_array($version?->reference_images)
            ? count($version->reference_images)
            : count($this->referenceImages);
        $documentCount = count($this->referenceDocuments);

        $lines = [
            'Konfigurasi prompt:',
            'Ide: '.trim($prompt?->idea ?: $this->idea),
            'Platform: '.$platformLabel,
            'Jenis keluaran: '.$promptTypeLabel,
        ];

        if ($imageCount > 0) {
            $lines[] = 'Gambar referensi: '.$imageCount.' gambar';
        }

        if ($documentCount > 0) {
            $lines[] = 'Dokumen acuan: '.$documentCount.' dokumen';
        }

        return implode("\n", $lines);
    }

    private function formatPromptTimestamp(mixed $value): string
    {
        return is_object($value) && method_exists($value, 'format') ? $value->format('H:i') : now()->format('H:i');
    }

    private function enforceRateLimit(string $action, int $maxAttempts, int $decaySeconds, string $message): void
    {
        $key = implode(':', [static::class, $action, 'user-'.Auth::id(), request()?->ip() ?? 'unknown']);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages(['rate_limit' => $message]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * Build reference_images override when user excluded some active images via X button.
     *
     * Returns empty array (no override) when nothing was excluded.
     * Returns ['exclude_reference_image_indices' => [...]] to signal the service.
     */
    private function buildExcludedImageOverrides(?GeneratedPromptVersion $baseVersion): array
    {
        $excluded = array_values(array_filter(
            array_map('intval', $this->excludedActiveReferenceImages),
            fn (int $i) => $i >= 0,
        ));

        if ($excluded === []) {
            return [];
        }

        $images = is_array($baseVersion?->reference_images) ? $baseVersion->reference_images : [];
        $kept = [];

        foreach ($images as $index => $image) {
            if (! in_array($index, $excluded, true)) {
                $kept[] = $image;
            }
        }

        return ['kept_reference_images' => $kept];
    }

}
