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
            $this->showPromptConfiguration = false;
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
        $this->applyPromptConfiguration($prompt);
        $this->promptChatMessages = $this->buildPromptChatMessages($prompt);
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
        $this->promptChatMessages = [];
        $this->isGenerating = false;
        $this->showPromptConfiguration = true;
        $this->isComposingNewPrompt = true;
        $this->statusMessage = null;
        $this->resetValidation();
        $this->dispatch('prompy-reference-image-cleared');
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
            ]);

            $this->activatePromptModel($prompt);
            $this->isComposingNewPrompt = false;
            $this->revisionInstruction = '';
            $this->showPromptConfiguration = false;
            $this->reset('referenceImages');
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
            'revisionInstruction.max' => 'Instruksi revisi maksimal 3000 karakter.',
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
            $updated = $service->revise(Auth::user(), $prompt, $this->revisionInstruction, $baseVersion);

            $this->activatePromptModel($updated);
            $this->revisionInstruction = '';
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

        $this->validate([
            'revisionInstruction' => ['required', 'string', 'max:'.PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH],
        ], [
            'revisionInstruction.required' => 'Pesan wajib diisi.',
            'revisionInstruction.max' => 'Pesan maksimal 3000 karakter.',
        ]);

        $userMessage = trim($this->revisionInstruction);
        $this->revisionInstruction = '';

        if ($userMessage === '') {
            return;
        }

        $this->enforceRateLimit('promptChat', 30, 60, 'Terlalu banyak pesan prompt. Coba lagi sebentar.');

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])
            ->where('id', $this->activePromptId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $prompt) {
            return;
        }

        $updated = $this->persistPromptChatExchange($prompt, [
            $this->makePromptChatMessage('user', $userMessage),
            $this->makePromptChatMessage('assistant', $this->promptChatReplyFor($userMessage, $prompt)),
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

        $instruction = 'Generate ulang prompt aktif dari konfigurasi terbaru. Gunakan ide, platform, jenis keluaran, dan gambar referensi terbaru dari panel konfigurasi sebagai acuan utama.';
        $overrides = [
            'idea' => $this->idea,
            'platform' => $this->platform,
            'prompt_type' => $this->promptType,
            'context_notes' => $this->contextNotes,
        ];

        if ($this->referenceImages !== []) {
            $overrides['reference_images'] = $this->referenceImages;
        }

        try {
            $updated = $service->revise(Auth::user(), $prompt, $instruction, $baseVersion, $overrides);

            $this->activatePromptModel($updated);
            $this->revisionInstruction = '';
            $this->isComposingNewPrompt = false;
            $this->showPromptConfiguration = false;
            $this->reset('referenceImages');
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
            $this->promptChatMessages = [];
            $this->isGenerating = false;
            $this->showPromptConfiguration = true;
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
                'content' => 'Revisi prompt "'.$prompt->displayTitle().'" berhasil disimpan sebagai Versi '.$version->version_number.'. Cek panel hasil untuk menyalin paket prompt terbaru.',
                'timestamp' => $this->formatPromptTimestamp($version->created_at),
            ];
        }

        return $this->mergePromptChatMessages(
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

            $mergedMessages = $this->mergePromptChatMessages(
                $this->normalizeStoredPromptChatMessages($lockedPrompt->chat_messages ?? []),
                $messages,
            );

            $lockedPrompt->forceFill(['chat_messages' => $mergedMessages])->save();

            return $lockedPrompt->fresh(['currentVersion', 'versions']);
        });
    }

    private function promptChatReplyFor(string $message, GeneratedPrompt $prompt): string
    {
        $message = trim($message);
        $versionLabel = 'Versi '.(int) ($prompt->currentVersion?->version_number ?? 1);
        $platformLabel = $prompt->platform_label ?: (PromptStudioService::PLATFORMS[$prompt->platform] ?? 'platform target');
        $promptTypeLabel = $prompt->prompt_type_label ?: (PromptStudioService::PROMPT_TYPES[$prompt->prompt_type] ?? 'output');

        if ($this->isShortUnclearPromptChatMessage($message)) {
            return 'Saya belum menangkap maksudnya. Coba tulis pertanyaan atau arahan yang lebih lengkap, misalnya apa yang ingin dicek dari prompt ini.';
        }

        if ($this->isPromptChatAcknowledgement($message)) {
            return 'Baik, saya pertahankan '.$versionLabel.' sebagai prompt aktif. Paket di panel hasil kanan sudah siap disalin saat diperlukan.';
        }

        if ($this->isPromptChatQuestion($message)) {
            return 'Untuk konteks prompt ini, paket aktif adalah '.$versionLabel.' untuk '.$platformLabel.' dengan keluaran '.$promptTypeLabel.'. Anda bisa menanyakan bagian prompt, meminta penjelasan, atau memakai tombol salin di panel hasil kanan.';
        }

        if ($this->looksLikePromptChangeNote($message)) {
            return 'Saya paham arahnya. Catatan itu relevan untuk prompt aktif, tetapi pesan chat ini tidak otomatis membuat package atau versi baru. Panel hasil kanan tetap memakai '.$versionLabel.' sampai Anda membuat ulang dari konfigurasi.';
        }

        return 'Saya catat untuk konteks prompt ini. Paket di panel hasil belum saya ubah otomatis, jadi Anda masih bisa berdiskusi atau memastikan arahnya dulu sebelum membuat ulang prompt.';
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

    private function mergePromptChatMessages(array $primaryMessages, array $secondaryMessages): array
    {
        $merged = [];
        $seen = [];

        foreach ([...$primaryMessages, ...$secondaryMessages] as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = ($message['role'] ?? null) === 'user' ? 'user' : 'assistant';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $key = $role.'|'.mb_strtolower((string) preg_replace('/\s+/u', ' ', $content));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = [
                'role' => $role,
                'content' => $content,
                'timestamp' => (string) ($message['timestamp'] ?? now()->format('H:i')),
            ];
        }

        return $merged;
    }

    private function isShortUnclearPromptChatMessage(string $message): bool
    {
        return mb_strlen(trim($message)) <= 2;
    }

    private function isPromptChatAcknowledgement(string $message): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)), 'UTF-8');
        $normalized = trim($normalized, " \t\n\r\0\x0B.!?,");

        return (bool) preg_match('/^(?:ok|oke|okay|sip|siap|mantap|bagus|sudah bagus|oke sudah bagus|terima kasih|makasih|thanks|thank you)$/iu', $normalized);
    }

    private function isPromptChatQuestion(string $message): bool
    {
        $normalized = mb_strtolower(trim($message), 'UTF-8');

        return str_contains($normalized, '?')
            || (bool) preg_match('/^(?:apa|apakah|bagaimana|gimana|kenapa|mengapa|bisa|boleh|cara|untuk apa)\b/iu', $normalized);
    }

    private function looksLikePromptChangeNote(string $message): bool
    {
        return (bool) preg_match('/\b(?:revisi|ubah|ganti|tambahkan|tambah|hapus|hilangkan|kurangi|perbaiki|jadikan|buat\s+(?:lebih|agar|jadi)|bikin\s+(?:lebih|agar|jadi)|pakai|gunakan|tiru|ikuti|pertahankan|masukkan|sertakan|sesuaikan|pendekkan|panjangkan|singkatkan|replace|change|add|remove|make|kurang|terlalu)\b/iu', $message);
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

        $lines = [
            'Konfigurasi prompt:',
            'Ide: '.trim($prompt?->idea ?: $this->idea),
            'Platform: '.$platformLabel,
            'Jenis keluaran: '.$promptTypeLabel,
        ];

        if ($imageCount > 0) {
            $lines[] = 'Gambar referensi: '.$imageCount.' gambar';
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

}
