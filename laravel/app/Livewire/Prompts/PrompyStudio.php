<?php

namespace App\Livewire\Prompts;

use App\Models\GeneratedPrompt;
use App\Services\Prompts\PromptStudioService;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Auth;
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

    public ?int $activePromptId = null;

    public ?int $activePromptVersionId = null;

    public string $revisionInstruction = '';

    public bool $isComposingNewPrompt = false;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $latestPrompt = GeneratedPrompt::with('currentVersion')
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
        $prompt = GeneratedPrompt::with('currentVersion')
            ->where('id', $promptId)
            ->where('user_id', Auth::id())
            ->first();

        if ($prompt) {
            $this->activatePromptModel($prompt);
            $this->isComposingNewPrompt = false;
            $this->revisionInstruction = '';
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
            return;
        }

        $this->activePromptId = $prompt->id;
        $this->activePromptVersionId = $versionId;
        $this->isComposingNewPrompt = false;
    }

    public function startNewPrompt(): void
    {
        $this->idea = '';
        $this->platform = null;
        $this->promptType = null;
        $this->contextNotes = '';
        $this->referenceImages = [];
        $this->activePromptId = null;
        $this->activePromptVersionId = null;
        $this->revisionInstruction = '';
        $this->isComposingNewPrompt = true;
        $this->statusMessage = null;
        $this->resetValidation();
        $this->dispatch('prompy-reference-image-cleared');
    }

    public function generate(PromptStudioService $service): void
    {
        $this->statusMessage = null;

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
        ]);

        $this->enforceRateLimit('generatePrompt', 10, 60, 'Terlalu banyak generate prompt. Coba lagi sebentar.');

        try {
            $prompt = $service->generate(Auth::user(), [
                'idea' => $this->idea,
                'platform' => $this->platform,
                'prompt_type' => $this->promptType,
                'context_notes' => $this->contextNotes,
                'reference_images' => $this->referenceImages,
            ]);

            $this->activePromptId = $prompt->id;
            $this->activePromptVersionId = $prompt->current_version_id;
            $this->isComposingNewPrompt = false;
            $this->revisionInstruction = '';
            $this->reset('referenceImages');
            $this->dispatch('prompy-reference-image-cleared');
            $this->statusMessage = 'Paket prompt berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal membuat paket prompt. Coba lagi sebentar.');
        }
    }

    public function revisePrompt(PromptStudioService $service): void
    {
        $this->statusMessage = null;

        $this->validate([
            'revisionInstruction' => ['required', 'string', 'max:'.PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH],
        ], [
            'revisionInstruction.required' => 'Instruksi revisi wajib diisi.',
            'revisionInstruction.max' => 'Instruksi revisi maksimal 3000 karakter.',
        ]);

        $this->enforceRateLimit('revisePrompt', 10, 60, 'Terlalu banyak revisi prompt. Coba lagi sebentar.');

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $this->activePromptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            return;
        }

        $baseVersion = $this->activePromptVersionId
            ? $prompt->versions->firstWhere('id', (int) $this->activePromptVersionId)
            : $prompt->currentVersion;

        try {
            $updated = $service->revise(Auth::user(), $prompt, $this->revisionInstruction, $baseVersion);

            $this->activePromptId = $updated->id;
            $this->activePromptVersionId = $updated->current_version_id;
            $this->revisionInstruction = '';
            $this->isComposingNewPrompt = false;
            $this->statusMessage = 'Revisi prompt berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal merevisi prompt. Coba lagi sebentar.');
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
        }
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
        $this->activePromptId = (int) $prompt->id;
        $this->activePromptVersionId = $prompt->current_version_id
            ? (int) $prompt->current_version_id
            : null;
    }

    private function enforceRateLimit(string $action, int $maxAttempts, int $decaySeconds, string $message): void
    {
        $key = implode(':', [static::class, $action, 'user-'.Auth::id(), request()?->ip() ?? 'unknown']);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages(['rate_limit' => $message]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

}
