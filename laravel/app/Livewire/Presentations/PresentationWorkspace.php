<?php

namespace App\Livewire\Presentations;

use App\Models\Document;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\OnlyOffice\ForceSaveException;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\PresentationDocumentKey;
use App\Services\OnlyOffice\PresentationForceSaveService;
use App\Services\Presentations\PresentationGenerationService;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Workspace mode Presentasi (epic #218, child #223).
 *
 * Sub-mode "Buat PPT ISTA": form konfigurasi hybrid + prompt, pilih dokumen
 * ready, generate async (pipeline #222), status/history, dan download PPTX/PDF
 * (#224). Sub-mode "Prompy Studio" masih placeholder (dikerjakan di #263).
 */
class PresentationWorkspace extends Component
{
    private const HISTORY_LOAD_LIMIT = 50;

    public string $subMode = 'create'; // create | prompy

    // Form konfigurasi PPT
    public string $title = '';

    public string $visualTemplate = 'resmi_klasik';

    public string $audience = '';

    public int $slideCount = 8;

    public string $header = '';

    public string $footer = '';

    public string $presenter = '';

    public string $unit = '';

    public string $additionalInstruction = '';

    /** @var array<int, int|string> */
    public array $selectedDocuments = [];

    public ?int $activePresentationId = null;

    /** Presentasi yang sedang dibuka di editor OnlyOffice Slides (#226). */
    public ?int $editingPresentationId = null;

    #[Locked]
    public ?array $editorConfigCache = null;

    #[Locked]
    public ?string $editorConfigCacheSignature = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->footer = 'Istana Kepresidenan Yogyakarta';
        $this->header = 'Istana Kepresidenan Yogyakarta';
    }

    public function setSubMode(string $mode): void
    {
        $this->subMode = in_array($mode, ['create', 'prompy'], true) ? $mode : 'create';
    }

    public function selectTemplate(string $key): void
    {
        if (array_key_exists($key, PresentationGenerationService::VISUAL_TEMPLATES)) {
            $this->visualTemplate = $key;
        }
    }

    public function toggleDocument(int $documentId): void
    {
        $documentId = (int) $documentId;
        $selected = array_map('intval', $this->selectedDocuments);

        if (in_array($documentId, $selected, true)) {
            $this->selectedDocuments = array_values(array_diff($selected, [$documentId]));

            return;
        }

        // Hanya izinkan dokumen milik user + ready.
        if (Presentation::sourceDocumentsOwnedAndReady((int) Auth::id(), [$documentId])) {
            $selected[] = $documentId;
            $this->selectedDocuments = array_values(array_unique($selected));
        }
    }

    public function generate(PresentationGenerationService $service): void
    {
        $this->statusMessage = null;

        $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'visualTemplate' => ['required', 'string', 'in:'.implode(',', array_keys(PresentationGenerationService::VISUAL_TEMPLATES))],
            'slideCount' => ['required', 'integer', 'between:'.PresentationGenerationService::SLIDE_COUNT_MIN.','.PresentationGenerationService::SLIDE_COUNT_MAX],
            'audience' => ['nullable', 'string', 'max:200'],
            'header' => ['nullable', 'string', 'max:200'],
            'footer' => ['nullable', 'string', 'max:200'],
            'presenter' => ['nullable', 'string', 'max:200'],
            'unit' => ['nullable', 'string', 'max:200'],
            'additionalInstruction' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'Judul presentasi wajib diisi.',
            'visualTemplate.in' => 'Template visual tidak dikenal.',
        ]);

        $this->enforceRateLimit('generatePresentation', 5, 60, 'Terlalu banyak generate presentasi. Coba lagi sebentar.');

        try {
            $presentation = $service->createAndDispatch(Auth::user(), [
                'title' => $this->title,
                'visual_template' => $this->visualTemplate,
                'audience' => $this->audience,
                'slide_count' => $this->slideCount,
                'header' => $this->header,
                'footer' => $this->footer,
                'presenter' => $this->presenter,
                'unit' => $this->unit,
                'additional_instruction' => $this->additionalInstruction,
                'source_document_ids' => $this->selectedDocuments,
            ]);

            $this->activePresentationId = $presentation->id;
            $this->statusMessage = 'Presentasi sedang dibuat. Status akan diperbarui otomatis.';
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = UserFacingError::message($e, 'Gagal memulai pembuatan presentasi. Coba lagi sebentar.');
        }
    }

    public function retry(int $presentationId, PresentationGenerationService $service): void
    {
        $presentation = Presentation::where('id', $presentationId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $presentation) {
            $this->statusMessage = 'Presentasi tidak ditemukan.';

            return;
        }

        if ($presentation->status === Presentation::STATUS_PROCESSING) {
            $this->statusMessage = 'Presentasi masih diproses.';

            return;
        }

        $this->enforceRateLimit('retryPresentation', 5, 60, 'Terlalu banyak percobaan ulang. Coba lagi sebentar.');

        $presentation->forceFill([
            'status' => Presentation::STATUS_PENDING,
            'error_message' => null,
        ])->save();

        $service->dispatchExisting($presentation);
        $this->activePresentationId = $presentation->id;
        $this->statusMessage = 'Presentasi dijadwalkan ulang untuk dibuat.';
    }

    public function deletePresentation(int $presentationId): void
    {
        $presentation = Presentation::where('id', $presentationId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $presentation) {
            return;
        }

        if ((int) $this->editingPresentationId === (int) $presentationId) {
            $this->closeEditor();
        }

        app(\App\Services\Presentations\PresentationLifecycleService::class)->delete($presentation);

        if ((int) $this->activePresentationId === (int) $presentationId) {
            $this->activePresentationId = null;
        }
    }

    /**
     * Buka presentasi yang sudah ready di editor OnlyOffice Slides (#226).
     */
    public function editPresentation(int $presentationId): void
    {
        $this->statusMessage = null;

        $presentation = Presentation::where('id', $presentationId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $presentation) {
            $this->statusMessage = 'Presentasi tidak ditemukan.';

            return;
        }

        if (! $presentation->isReady() || ! $presentation->pptx_path) {
            $this->statusMessage = 'Presentasi belum siap untuk diedit.';

            return;
        }

        $this->editingPresentationId = $presentation->id;
        $this->activePresentationId = $presentation->id;
        $this->forgetEditorConfigCache();
    }

    public function closeEditor(): void
    {
        // Force-save sebelum menutup agar editan manual tidak hilang.
        $this->syncActiveEditorBeforeLeaving();
        $this->editingPresentationId = null;
        $this->forgetEditorConfigCache();
    }

    /**
     * Force-save editor aktif. Mengembalikan false bila penyimpanan gagal agar
     * pemanggil bisa menahan perpindahan.
     */
    protected function syncActiveEditorBeforeLeaving(): bool
    {
        if (! $this->editingPresentationId) {
            return true;
        }

        $presentation = Presentation::with('currentVersion')
            ->where('id', $this->editingPresentationId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $presentation || ! $presentation->pptx_path) {
            return true;
        }

        $version = $this->editorVersion($presentation);

        try {
            app(PresentationForceSaveService::class)->forceSave($presentation, $version);

            return true;
        } catch (ForceSaveException $e) {
            $this->statusMessage = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $this->statusMessage = 'Perubahan editor belum tersimpan. Coba lagi sebentar.';
        }

        return false;
    }

    protected function editorVersion(Presentation $presentation): ?PresentationVersion
    {
        return $presentation->currentVersion
            ?: $presentation->versions()->orderByDesc('version_number')->first();
    }

    /**
     * Bangun konfigurasi editor OnlyOffice Slides untuk presentasi aktif.
     *
     * @return array<string, mixed>|null
     */
    public function editorConfig(): ?array
    {
        if (! $this->editingPresentationId) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $presentation = Presentation::with('currentVersion')
            ->where('id', $this->editingPresentationId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $presentation || ! $presentation->isReady()) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $version = $this->editorVersion($presentation);
        $filePath = $version?->pptx_path ?: $presentation->pptx_path;

        if (! $filePath) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $versionId = $version?->id;
        $cacheSignature = implode(':', [
            Auth::id(),
            $presentation->id,
            $versionId ?? 'current',
            $filePath,
        ]);

        if ($this->editorConfigCacheSignature === $cacheSignature && $this->editorConfigCache !== null) {
            return $this->editorConfigCache;
        }

        $signer = app(JwtSigner::class);
        $laravelInternalUrl = rtrim((string) config('services.onlyoffice.laravel_internal_url', config('app.url')), '/');
        $ttlMinutes = max(1, (int) config('services.onlyoffice.signed_url_ttl_minutes', 30));
        $documentUrl = app(PresentationDocumentKey::class)->signedFileUrl($presentation, $versionId, $ttlMinutes);
        $callbackPath = route('onlyoffice.presentation.callback', array_filter([
            'presentation' => $presentation->id,
            'version_id' => $versionId,
        ], fn ($value) => filled($value)), false);

        $documentKey = app(PresentationDocumentKey::class)->forEditor($presentation, $version);
        $config = [
            'document' => [
                'fileType' => 'pptx',
                'key' => $documentKey,
                'title' => $presentation->title.'.pptx',
                'url' => $documentUrl,
            ],
            'documentType' => 'slide',
            'editorConfig' => [
                'callbackUrl' => $laravelInternalUrl.$callbackPath,
                'customization' => [
                    'forcesave' => true,
                ],
                'mode' => 'edit',
                'lang' => 'id',
                'user' => [
                    'id' => (string) Auth::id(),
                    'name' => $this->safeEditorUserName((string) Auth::user()?->name),
                ],
            ],
            'exp' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ];

        $config['token'] = $signer->sign($config);

        $this->editorConfigCacheSignature = $cacheSignature;
        $this->editorConfigCache = $config;

        return $this->editorConfigCache;
    }

    protected function safeEditorUserName(string $name): string
    {
        $clean = preg_replace('/[<>"`]+/u', ' ', $name) ?? '';
        $clean = preg_replace("/[^\p{L}\p{M}\p{N}\s.'-]+/u", ' ', $clean) ?? '';
        $clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? '';

        return mb_substr($clean !== '' ? $clean : 'Pengguna', 0, 120);
    }

    protected function forgetEditorConfigCache(): void
    {
        $this->editorConfigCache = null;
        $this->editorConfigCacheSignature = null;
    }

    public function render()
    {
        $userId = (int) Auth::id();

        $presentations = Presentation::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LOAD_LIMIT)
            ->get();

        $availableDocuments = Document::where('user_id', $userId)
            ->where('status', 'ready')
            ->orderByDesc('created_at')
            ->get(['id', 'original_name', 'created_at']);

        $hasInProgress = $presentations->contains(
            fn (Presentation $p) => in_array($p->status, [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING], true)
        );

        return view('livewire.presentations.presentation-workspace', [
            'presentations' => $presentations,
            'availableDocuments' => $availableDocuments,
            'templates' => PresentationGenerationService::VISUAL_TEMPLATES,
            'hasInProgress' => $hasInProgress,
            'slideCountMin' => PresentationGenerationService::SLIDE_COUNT_MIN,
            'slideCountMax' => PresentationGenerationService::SLIDE_COUNT_MAX,
            'editorConfig' => $this->editorConfig(),
            'onlyOfficeApiUrl' => rtrim((string) config('services.onlyoffice.public_url', ''), '/').'/web-apps/apps/api/documents/api.js',
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
