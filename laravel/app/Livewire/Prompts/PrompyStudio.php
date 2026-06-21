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

    public string $platform = 'generic';

    public string $promptType = 'image';

    public string $contextNotes = '';

    /** Reference image privat yang dianalisis model vision. */
    public $referenceImage = null;

    public ?int $activePromptId = null;

    public bool $isComposingNewPrompt = false;

    public ?string $statusMessage = null;

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
        $prompt = GeneratedPrompt::where('id', $promptId)
            ->where('user_id', Auth::id())
            ->first();

        if ($prompt) {
            $this->activePromptId = $prompt->id;
            $this->isComposingNewPrompt = false;
        }
    }

    public function startNewPrompt(): void
    {
        $this->idea = '';
        $this->platform = 'generic';
        $this->promptType = 'image';
        $this->contextNotes = '';
        $this->referenceImage = null;
        $this->activePromptId = null;
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
            'referenceImage' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.(int) (PromptStudioService::REFERENCE_IMAGE_MAX_BYTES / 1024),
            ],
        ], [
            'idea.required' => 'Ide prompt wajib diisi.',
            'platform.in' => 'Platform tidak dikenal.',
            'promptType.in' => 'Jenis prompt tidak dikenal.',
            'referenceImage.image' => 'Gambar referensi harus berupa file gambar.',
            'referenceImage.mimes' => 'Gambar referensi harus JPG atau PNG.',
            'referenceImage.max' => 'Ukuran gambar referensi maksimal 5 MB.',
        ]);

        $this->enforceRateLimit('generatePrompt', 10, 60, 'Terlalu banyak generate prompt. Coba lagi sebentar.');

        try {
            $prompt = $service->generate(Auth::user(), [
                'idea' => $this->idea,
                'platform' => $this->platform,
                'prompt_type' => $this->promptType,
                'context_notes' => $this->contextNotes,
                'reference_image' => $this->referenceImage,
            ]);

            $this->activePromptId = $prompt->id;
            $this->isComposingNewPrompt = false;
            $this->reset('referenceImage');
            $this->dispatch('prompy-reference-image-cleared');
            $this->statusMessage = 'Paket prompt berhasil dibuat.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal membuat paket prompt. Coba lagi sebentar.');
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
        }
    }

    public function render()
    {
        $userId = (int) Auth::id();

        $prompts = GeneratedPrompt::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LOAD_LIMIT)
            ->get();

        $activePrompt = $this->isComposingNewPrompt
            ? null
            : ($this->activePromptId
                ? $prompts->firstWhere('id', (int) $this->activePromptId)
                : $prompts->first());

        return view('livewire.prompts.prompy-studio', [
            'prompts' => $prompts,
            'activePrompt' => $activePrompt,
            'platforms' => PromptStudioService::PLATFORMS,
            'promptTypes' => PromptStudioService::PROMPT_TYPES,
        ]);
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
