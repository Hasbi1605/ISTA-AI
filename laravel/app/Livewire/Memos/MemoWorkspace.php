<?php

namespace App\Livewire\Memos;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Services\Memo\MemoDocumentStructureExtractor;
use App\Services\Memo\MemoGenerationService;
use App\Services\Memo\MemoLifecycleService;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\MemoDocumentKey;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MemoWorkspace extends Component
{
    private const DRAFT_THREAD_KEY = 'draft';

    private const HISTORY_LOAD_LIMIT = 100;

    public ?int $activeMemoId = null;

    public ?int $activeMemoVersionId = null;

    #[Locked]
    public ?array $editorConfigCache = null;

    #[Locked]
    public ?string $editorConfigCacheSignature = null;

    public string $memoType = 'memo_internal';

    public string $title = '';

    public string $memoPrompt = '';

    public string $memoNumber = '';

    public string $memoRecipient = '';

    public string $memoSender = 'Kepala Istana Kepresidenan Yogyakarta';

    public string $memoDate = '';

    public string $memoBasis = '';

    public string $memoContent = '';

    public string $memoClosing = '';

    public string $memoSignatory = 'Deni Mulyana';

    public string $memoCarbonCopy = '';

    public string $memoPageSize = 'auto';

    public string $memoAdditionalInstruction = '';

    public string $memoRegenerateMode = 'preserve_document';

    public array $memoChatMessages = [];

    public array $memoChatThreads = [];

    public bool $isGenerating = false;

    public bool $showMemoConfiguration = true;

    public ?string $memoStatusMessage = null;

    private bool $skipRateLimitForNestedGeneration = false;

    public function mount(): void
    {
        $this->resetMemoConfiguration();
        $this->addSystemMessage('Lengkapi konfigurasi memo terlebih dahulu. Setelah draft dibuat, kolom revisi akan aktif di panel yang sama.');

        $memoId = request()->integer('memo');
        if ($memoId > 0) {
            $this->loadMemo($memoId);
        }
    }

    public function loadMemo(int $memoId): void
    {
        $this->rememberCurrentThread();
        $this->memoStatusMessage = null;

        $memo = Memo::with(['currentVersion', 'versions'])
            ->where('id', $memoId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $memo) {
            $this->memoStatusMessage = 'Memo tidak ditemukan atau Anda tidak memiliki akses.';
            $this->addSystemMessage('Memo tidak ditemukan atau Anda tidak memiliki akses. Pilih memo lain dari riwayat atau buat memo baru.');

            return;
        }

        $this->activeMemoId = $memo->id;
        $this->activeMemoVersionId = $this->resolveMemoVersion($memo)?->id;
        $this->memoType = $memo->memo_type;
        $this->title = $memo->title;
        $this->applyMemoConfiguration($this->activeMemoConfiguration($memo));
        $this->showMemoConfiguration = false;
        $this->memoPrompt = '';

        $threadKey = $this->threadKey($memo->id);

        $storedThread = $this->threadWithRevisionInstructions(
            $this->normalizeStoredThread($memo->chat_messages ?? []),
            $memo,
        );
        $cachedThread = $this->normalizeStoredThread($this->memoChatThreads[$threadKey] ?? []);
        $mergedThread = $this->mergeMemoChatThreads($storedThread, $cachedThread);

        if ($mergedThread !== []) {
            $this->memoChatMessages = $mergedThread;
            $this->rememberCurrentThread();

            return;
        }

        $this->memoChatMessages = [];
        $this->rememberCurrentThread();
    }

    public function startNewMemo(): void
    {
        $this->rememberCurrentThread();

        $this->activeMemoId = null;
        $this->activeMemoVersionId = null;
        $this->memoPrompt = '';
        $this->memoChatMessages = [];
        $this->isGenerating = false;
        $this->resetMemoConfiguration();
        $this->showMemoConfiguration = true;
        $this->addSystemMessage('Lengkapi konfigurasi memo baru. Saya akan menjaga struktur, gaya bahasa, dan format memorandum mengikuti contoh manual.');
    }

    public function generateConfiguredMemo(): void
    {
        if (! $this->validateMemoConfiguration()) {
            return;
        }

        $this->enforceRateLimit('generateConfiguredMemo', 5, 60, 'Terlalu banyak generate memo. Coba lagi sebentar.');

        $this->memoChatMessages[] = [
            'role' => 'user',
            'content' => $this->memoConfigurationSummary(),
            'timestamp' => now()->format('H:i'),
        ];
        $this->rememberCurrentThread();

        if ($this->activeMemoId) {
            $this->generateConfiguredRevision();

            return;
        }

        $this->generateFromChat($this->memoDraftContext(), true);
    }

    public function sendMemoChat(?string $message = null): void
    {
        if ($message !== null) {
            $this->memoPrompt = $message;
        }

        $this->validate([
            'memoPrompt' => 'required|string|min:1',
        ]);

        $userMessage = trim($this->memoPrompt);
        $this->memoPrompt = '';

        $this->memoChatMessages[] = [
            'role' => 'user',
            'content' => $userMessage,
            'timestamp' => now()->format('H:i'),
        ];
        $this->rememberCurrentThread();

        if ($this->activeMemoId) {
            $this->generateRevisionFromChat($userMessage);
        } else {
            $this->memoContent = trim($this->memoContent."\n".$userMessage);
            $this->addSystemMessage('Saya simpan instruksi itu sebagai isi/poin memo. Lengkapi konfigurasi utama, lalu tekan Generate Memo.');
        }
    }

    public function generateRevisionFromChat(string $instruction): void
    {
        if (! $this->skipRateLimitForNestedGeneration) {
            $this->enforceRateLimit('generateRevisionFromChat', 5, 60, 'Terlalu banyak revisi memo. Coba lagi sebentar.');
        }

        if (! $this->validateMemoConfiguration()) {
            return;
        }

        $this->isGenerating = true;

        try {
            $bodyOverride = $this->currentMemoBodyForRevision();
            $this->applyRevisionInstruction($instruction);
            $bodyOverride = $this->applyBodyOnlyRevisionInstruction($bodyOverride, $instruction);

            $generationService = app(MemoGenerationService::class);
            $configuration = array_merge($this->memoConfigurationPayload(), [
                'revision_instruction' => trim($instruction),
            ]);

            $memo = Memo::where('id', $this->activeMemoId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $version = $this->shouldPreserveCurrentBodyForRevision($instruction, $bodyOverride) && $bodyOverride !== ''
                ? $generationService->generateRevisionFromBody(
                    $memo,
                    $bodyOverride,
                    $configuration,
                    trim($instruction),
                )
                : $generationService->generateRevision(
                    $memo,
                    $this->memoRevisionContext($instruction),
                    $configuration,
                    trim($instruction),
                );

            $memo = $version->memo()->firstOrFail();

            $this->activeMemoId = $memo->id;
            $this->activeMemoVersionId = $version->id;
            $this->title = $memo->title;
            $this->applyMemoConfiguration($version->configuration ?? []);
            $this->showMemoConfiguration = false;
            $this->rememberCurrentThread();

            $this->addSystemMessage("Revisi memo \"{$memo->title}\" berhasil disimpan sebagai Versi {$version->version_number}. Cek panel Dokumen untuk memastikan hasilnya sudah sesuai.");
            $this->dispatch('memo-document-ready', memoId: $memo->id);
        } catch (\Throwable $e) {
            report($e);
            $this->addSystemMessage(UserFacingError::message($e, 'Maaf, revisi memo gagal diproses. Silakan coba lagi.'));
        } finally {
            $this->isGenerating = false;
        }
    }

    public function generateConfiguredRevision(): void
    {
        if (! $this->skipRateLimitForNestedGeneration) {
            $this->enforceRateLimit('generateConfiguredRevision', 5, 60, 'Terlalu banyak generate ulang memo. Coba lagi sebentar.');
        }

        if (! $this->validateMemoConfiguration()) {
            return;
        }

        $this->isGenerating = true;

        try {
            $instruction = $this->memoRegenerateMode === 'form_only'
                ? 'Generate ulang memo aktif dari konfigurasi terbaru. Gunakan seluruh metadata, isi, penutup, penandatangan, tembusan, dan format dokumen yang sedang tampil di panel konfigurasi.'
                : 'Generate ulang memo aktif dari konfigurasi terbaru. Gunakan metadata dari panel konfigurasi dan pertahankan isi dokumen terbaru sebagai baseline kecuali konfigurasi meminta perubahan.';
            $generationService = app(MemoGenerationService::class);
            $configuration = array_merge($this->memoConfigurationPayload(), [
                'revision_instruction' => $instruction,
            ]);

            $memo = Memo::where('id', $this->activeMemoId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $version = $generationService->generateRevision(
                $memo,
                $this->memoConfiguredRevisionContext($instruction),
                $configuration,
                $instruction,
            );

            $memo = $version->memo()->firstOrFail();

            $this->activeMemoId = $memo->id;
            $this->activeMemoVersionId = $version->id;
            $this->title = $memo->title;
            $this->applyMemoConfiguration($version->configuration ?? []);
            $this->showMemoConfiguration = false;
            $this->rememberCurrentThread();

            $this->addSystemMessage("Memo \"{$memo->title}\" berhasil digenerate ulang dari konfigurasi sebagai Versi {$version->version_number}. History tetap berada pada memo yang sama.");
            $this->dispatch('memo-document-ready', memoId: $memo->id);
        } catch (\Throwable $e) {
            report($e);
            $this->addSystemMessage(UserFacingError::message($e, 'Maaf, generate ulang memo gagal diproses. Silakan coba lagi.'));
        } finally {
            $this->isGenerating = false;
        }
    }

    public function generateFromChat(string $context, bool $fromConfiguration = false): void
    {
        if (! $this->skipRateLimitForNestedGeneration) {
            $this->enforceRateLimit('generateFromChat', 5, 60, 'Terlalu banyak generate memo. Coba lagi sebentar.');
        }

        if (! $this->validateMemoConfiguration()) {
            return;
        }

        $this->isGenerating = true;

        try {
            $generationService = app(MemoGenerationService::class);
            $configuration = $this->memoConfigurationPayload();

            $memo = $generationService->generate(
                Auth::user(),
                $this->memoType,
                $this->title,
                $context,
                [],
                $configuration,
            );

            $this->activeMemoId = $memo->id;
            $this->activeMemoVersionId = $memo->current_version_id;
            $this->showMemoConfiguration = false;
            $this->rememberCurrentThread();

            $message = $fromConfiguration
                ? "Draft memo \"{$memo->title}\" berhasil digenerate dari konfigurasi. Anda bisa meminta revisi spesifik di sini."
                : "Revisi memo \"{$memo->title}\" berhasil digenerate. Cek panel Dokumen untuk memastikan hasilnya sudah sesuai.";

            $this->addSystemMessage($message);
            $this->dispatch('memo-document-ready', memoId: $memo->id);
        } catch (\Throwable $e) {
            report($e);
            $this->addSystemMessage(UserFacingError::message($e, 'Maaf, generate memo gagal diproses. Silakan coba lagi.'));
        } finally {
            $this->isGenerating = false;
        }
    }

    public function regenerate(): void
    {
        $this->enforceRateLimit('regenerate', 5, 60, 'Terlalu banyak regenerate memo. Coba lagi sebentar.');

        if (! $this->activeMemoId) {
            $this->skipRateLimitForNestedGeneration = true;
            try {
                $this->generateConfiguredMemo();
            } finally {
                $this->skipRateLimitForNestedGeneration = false;
            }

            return;
        }

        $this->skipRateLimitForNestedGeneration = true;
        try {
            $this->generateRevisionFromChat('Generate ulang memo aktif dari konfigurasi terakhir. Pertahankan metadata, struktur, dan bagian yang tidak perlu diubah.');
        } finally {
            $this->skipRateLimitForNestedGeneration = false;
        }
    }

    public function switchMemoVersion(int|string $versionId): void
    {
        if (! $this->activeMemoId || ! is_numeric($versionId)) {
            return;
        }

        $memo = Memo::where('id', $this->activeMemoId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $memo) {
            $this->addSystemMessage('Memo aktif tidak ditemukan. Pilih memo lain dari riwayat atau buat memo baru.');

            return;
        }

        $version = MemoVersion::where('memo_id', $memo->id)
            ->whereKey((int) $versionId)
            ->first();

        if (! $version) {
            $this->addSystemMessage('Versi memo tidak ditemukan. Pilih versi lain dari riwayat versi.');

            return;
        }

        $this->activeMemoVersionId = $version->id;
        $this->title = (string) data_get($version->configuration, 'subject', $memo->title);
        $this->applyMemoConfiguration($version->configuration ?? []);
        $this->showMemoConfiguration = false;
        $this->rememberCurrentThread();
        $this->dispatch('memo-document-ready', memoId: $memo->id);
    }

    public function deleteMemo(int $memoId): void
    {
        $memo = Memo::with('versions')
            ->where('id', $memoId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $memo) {
            return;
        }

        $wasActiveMemo = (int) $this->activeMemoId === (int) $memo->id;

        unset($this->memoChatThreads[$this->threadKey($memo->id)]);

        app(MemoLifecycleService::class)->deleteMemo($memo);

        if (! $wasActiveMemo) {
            return;
        }

        $this->activeMemoId = null;
        $this->activeMemoVersionId = null;
        $this->memoPrompt = '';
        $this->memoChatMessages = [];
        $this->isGenerating = false;
        $this->resetMemoConfiguration();
        $this->showMemoConfiguration = true;
        $this->addSystemMessage('Memo berhasil dihapus dari history. Lengkapi konfigurasi untuk membuat memo baru.');
    }

    public function editorConfig(): ?array
    {
        if (! $this->activeMemoId) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $memo = Memo::with('currentVersion')
            ->where('id', $this->activeMemoId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $memo) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $version = $this->editorMemoVersion($memo);

        $filePath = $version?->file_path ?: $memo->file_path;

        if (! $filePath) {
            $this->forgetEditorConfigCache();

            return null;
        }

        $versionId = $version?->id;
        $cacheSignature = implode(':', [
            Auth::id(),
            $memo->id,
            $versionId ?? 'current',
            $filePath,
        ]);

        if ($this->editorConfigCacheSignature === $cacheSignature && $this->editorConfigCache !== null) {
            return $this->editorConfigCache;
        }

        $signer = app(JwtSigner::class);
        $laravelInternalUrl = rtrim((string) config('services.onlyoffice.laravel_internal_url', config('app.url')), '/');
        $ttlMinutes = max(1, (int) config('services.onlyoffice.signed_url_ttl_minutes', 30));
        $ooToken = app(MemoDocumentKey::class)->generateFileToken($memo, $versionId, $ttlMinutes);
        $documentPath = URL::temporarySignedRoute('memos.file.signed', now()->addMinutes($ttlMinutes), array_filter([
            'memo' => $memo,
            'version_id' => $versionId,
            'oo_token' => $ooToken,
        ], fn ($value) => filled($value)), false);
        $callbackPath = route('onlyoffice.callback', array_filter([
            'memo' => $memo,
            'version_id' => $versionId,
        ], fn ($value) => filled($value)), false);

        $documentKey = app(MemoDocumentKey::class)->forEditor($memo, $version);
        $config = [
            'document' => [
                'fileType' => 'docx',
                'key' => $documentKey,
                'title' => $memo->title.'.docx',
                'url' => $laravelInternalUrl.$documentPath,
            ],
            'documentType' => 'word',
            'editorConfig' => [
                'callbackUrl' => $laravelInternalUrl.$callbackPath,
                'customization' => [
                    'forcesave' => true,
                ],
                'mode' => 'edit',
                'lang' => 'id',
                'user' => [
                    'id' => (string) Auth::id(),
                    'name' => (string) Auth::user()?->name,
                ],
            ],
            'exp' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ];

        $config['token'] = $signer->sign($config);

        $this->editorConfigCacheSignature = $cacheSignature;
        $this->editorConfigCache = $config;

        return $this->editorConfigCache;
    }

    protected function forgetEditorConfigCache(): void
    {
        $this->editorConfigCache = null;
        $this->editorConfigCacheSignature = null;
    }

    protected function editorMemoVersion(Memo $memo): ?MemoVersion
    {
        if ($this->activeMemoVersionId) {
            $version = MemoVersion::where('memo_id', $memo->id)
                ->whereKey($this->activeMemoVersionId)
                ->first();

            if ($version) {
                return $version;
            }
        }

        return $memo->currentVersion
            ?: $memo->versions()->orderByDesc('version_number')->first();
    }

    public function render()
    {
        $memos = Memo::with('currentVersion')
            ->where('user_id', Auth::id())
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::HISTORY_LOAD_LIMIT)
            ->get();

        $activeMemoVersions = $this->activeMemoId
            ? MemoVersion::whereHas('memo', fn ($query) => $query->where('user_id', Auth::id()))
                ->where('memo_id', $this->activeMemoId)
                ->orderByDesc('version_number')
                ->get()
            : collect();

        return view('livewire.memos.memo-workspace', [
            'memos' => $memos,
            'activeMemoVersions' => $activeMemoVersions,
            'memoTypes' => Memo::TYPES,
            'memoPageSizes' => $this->memoPageSizes(),
            'editorConfig' => $this->editorConfig(),
            'onlyOfficeApiUrl' => rtrim((string) config('services.onlyoffice.public_url', ''), '/').'/web-apps/apps/api/documents/api.js',
        ]);
    }

    protected function validateMemoConfiguration(): bool
    {
        try {
            $this->validate([
                'memoType' => ['required', 'in:'.implode(',', array_keys(Memo::TYPES))],
                'memoNumber' => ['required', 'string', 'max:80'],
                'memoRecipient' => ['required', 'string', 'max:500'],
                'memoSender' => ['required', 'string', 'max:240'],
                'title' => ['required', 'string', 'max:160'],
                'memoDate' => ['required', 'string', 'max:80'],
                'memoBasis' => ['nullable', 'string', 'max:4000'],
                'memoContent' => ['required', 'string', 'min:8', 'max:8000'],
                'memoClosing' => ['nullable', 'string', 'max:800'],
                'memoSignatory' => ['nullable', 'string', 'max:160'],
                'memoCarbonCopy' => ['nullable', 'string', 'max:2000'],
                'memoPageSize' => ['required', 'in:auto,folio,letter'],
                'memoAdditionalInstruction' => ['nullable', 'string', 'max:2000'],
                'memoRegenerateMode' => ['required', 'in:preserve_document,form_only'],
            ], [
                'memoNumber.required' => 'Nomor memo wajib diisi.',
                'memoRecipient.required' => 'Yth. wajib diisi.',
                'memoSender.required' => 'Dari wajib diisi.',
                'title.required' => 'Hal wajib diisi.',
                'memoDate.required' => 'Tanggal wajib diisi.',
                'memoContent.required' => 'Isi / poin wajib harus diisi.',
                'memoContent.min' => 'Isi / poin wajib minimal :min karakter.',
            ]);

            return true;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->dispatch('memo-configuration-invalid');

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function memoConfigurationPayload(): array
    {
        return [
            'number' => trim($this->memoNumber),
            'recipient' => trim($this->memoRecipient),
            'sender' => trim($this->memoSender),
            'subject' => trim($this->title),
            'date' => trim($this->memoDate),
            'basis' => trim($this->memoBasis),
            'content' => trim($this->memoContent),
            'closing' => trim($this->memoClosing),
            'signatory' => trim($this->memoSignatory),
            'carbon_copy' => trim($this->memoCarbonCopy),
            'page_size' => $this->memoPageSize === 'auto' ? 'auto' : $this->resolveMemoPageSize(),
            'page_size_mode' => trim($this->memoPageSize),
            'additional_instruction' => trim($this->memoAdditionalInstruction),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function memoStructureHints(): array
    {
        return [
            'number' => trim($this->memoNumber),
            'recipient' => trim($this->memoRecipient),
            'sender' => trim($this->memoSender),
            'subject' => trim($this->title),
            'date' => trim($this->memoDate),
            'closing' => trim($this->memoClosing),
            'signatory' => trim($this->memoSignatory),
            'carbon_copy' => trim($this->memoCarbonCopy),
        ];
    }

    protected function memoDraftContext(): string
    {
        $sections = [];

        if (trim($this->memoBasis) !== '') {
            $sections[] = "Dasar/konteks:\n".trim($this->memoBasis);
        }

        $sections[] = "Isi/poin wajib:\n".trim($this->memoContent);

        if (trim($this->memoAdditionalInstruction) !== '') {
            $sections[] = "Arahan tambahan:\n".trim($this->memoAdditionalInstruction);
        }

        return implode("\n\n", $sections);
    }

    protected function memoConfiguredRevisionContext(string $instruction): string
    {
        $sections = [
            $this->memoDraftContext(),
            "Instruksi revisi wajib diterapkan:\n".trim($instruction),
        ];

        if ($this->memoRegenerateMode === 'preserve_document') {
            $bodyOnly = $this->currentMemoBodyForRevision();

            if ($bodyOnly !== '') {
                array_unshift($sections, "Isi memo saat ini:\n".$bodyOnly);
            }
        }

        return implode("\n\n", array_filter($sections, fn (string $section) => trim($section) !== ''));
    }

    protected function memoRevisionContext(string $instruction): string
    {
        $sections = [
            $this->memoDraftContext(),
            "Instruksi revisi wajib diterapkan:\n".trim($instruction),
        ];

        $bodyOnly = $this->currentMemoBodyForRevision();

        if ($bodyOnly !== '') {
            array_unshift($sections, "Isi memo saat ini:\n".$bodyOnly);
        }

        return implode("\n\n", array_filter($sections, fn (string $section) => trim($section) !== ''));
    }

    protected function currentMemoBodyForRevision(): string
    {
        $activeMemoText = null;

        if ($this->activeMemoVersionId) {
            $activeMemoText = MemoVersion::whereHas('memo', fn ($query) => $query->where('user_id', Auth::id()))
                ->whereKey($this->activeMemoVersionId)
                ->value('searchable_text');
        }

        if (! $activeMemoText && $this->activeMemoId) {
            $activeMemoText = Memo::where('id', $this->activeMemoId)
                ->where('user_id', Auth::id())
                ->value('searchable_text');
        }

        if (trim((string) $activeMemoText) === '') {
            return '';
        }

        return app(MemoDocumentStructureExtractor::class)->bodyFromSearchableText(
            (string) $activeMemoText,
            $this->memoStructureHints(),
        );
    }

    protected function shouldPreserveCurrentBodyForRevision(string $instruction, string $body = ''): bool
    {
        $normalized = mb_strtolower(trim($instruction));

        if ($normalized === '') {
            return false;
        }

        if ($this->isNarrowTypoNameRevision($instruction)) {
            return $this->extractTypoNameRevisionValue($instruction) !== ''
                && $this->bodyContainsConfiguredNameLine($body);
        }

        if ($this->isNumberedFormatRevision($normalized)) {
            return $this->bodyHasNumberedList($body);
        }

        $bodyChangePatterns = [
            '/\bpoin\b/u',
            '/\bparagraf\b/u',
            '/\blebih\s+singkat\b/u',
            '/\bringkas\b/u',
            '/\bdipersingkat\b/u',
            '/\bformat\b/u',
            '/\btypo\b/u',
            '/\btanggal\s+(?:rapat|kegiatan)\b/u',
            '/\bdalam\s+isi\b/u',
            '/\bbagian\s+isi\b/u',
            '/\bdata\s+utama\b/u',
            '/\brapikan\s+bagian\b/u',
        ];

        foreach ($bodyChangePatterns as $pattern) {
            if (preg_match($pattern, $normalized) && ! $this->hasBodyPreservationInstruction($normalized)) {
                return false;
            }
        }

        return preg_match(
            '/\b(?:tembusan|penerima|yth\.?|kalimat\s+penutup|penutup|penandatangan|tanda\s+tangan|tanggal\s+memo)\b/u',
            $normalized,
        ) === 1;
    }

    protected function hasBodyPreservationInstruction(string $normalizedInstruction): bool
    {
        return preg_match(
            '/\b(?:bagian\s+isi|isi|body|bagian\s+lain|data\s+lain|yang\s+lain)\b.*\b(?:jangan\s+(?:diubah|berubah|mengubah)|pertahankan|tetap|tanpa\s+perubahan)\b/u',
            $normalizedInstruction,
        ) === 1
            || preg_match(
                '/\b(?:jangan\s+(?:diubah|berubah|mengubah)|pertahankan|tetap|tanpa\s+perubahan)\b.*\b(?:bagian\s+isi|isi|body|bagian\s+lain|data\s+lain|yang\s+lain)\b/u',
                $normalizedInstruction,
            ) === 1;
    }

    protected function memoConfigurationSummary(): string
    {
        $summary = [
            'Konfigurasi memo:',
            'Nomor: '.trim($this->memoNumber),
            'Yth.: '.trim($this->memoRecipient),
            'Dari: '.trim($this->memoSender),
            'Hal: '.trim($this->title),
            'Tanggal: '.trim($this->memoDate),
            'Penandatangan: '.trim($this->memoSignatory),
        ];

        if (trim($this->memoCarbonCopy) !== '') {
            $summary[] = "Tembusan:\n".trim($this->memoCarbonCopy);
        }

        return implode("\n", $summary);
    }

    protected function applyRevisionInstruction(string $instruction): void
    {
        $this->applyRecipientRevision($instruction);
        $this->applyDateRevision($instruction);
        $this->applyClosingRevision($instruction);
        $this->applySignatoryRevision($instruction);
        $this->applyCarbonCopyRevision($instruction);
        $this->applyCarbonCopyCasingRevision($instruction);
        $this->applyTypoNameRevision($instruction);
    }

    protected function applyBodyOnlyRevisionInstruction(string $body, string $instruction): string
    {
        $cleanBody = trim($body);

        if ($cleanBody === '') {
            return '';
        }

        if ($this->isNarrowTypoNameRevision($instruction)) {
            $value = $this->extractTypoNameRevisionValue($instruction);

            if ($value !== '') {
                return $this->replaceConfiguredNameLine($cleanBody, $value);
            }
        }

        return $cleanBody;
    }

    protected function applyTypoNameRevision(string $instruction): void
    {
        if (! $this->isNarrowTypoNameRevision($instruction)) {
            return;
        }

        $value = $this->extractTypoNameRevisionValue($instruction);

        if ($value === '') {
            return;
        }

        $this->memoContent = $this->replaceConfiguredNameLine($this->memoContent, $value);
    }

    protected function isNarrowTypoNameRevision(string $instruction): bool
    {
        return preg_match('/\btypo\b/iu', $instruction) === 1
            && preg_match('/\bnama\b/iu', $instruction) === 1;
    }

    protected function isNumberedFormatRevision(string $normalizedInstruction): bool
    {
        return preg_match('/\b(?:format|ubah|jadikan|menjadi)\b.*\bpoin\s+bernomor\b/u', $normalizedInstruction) === 1
            || preg_match('/\bpoin\s+bernomor\b.*\b(?:format|ubah|jadikan|menjadi)\b/u', $normalizedInstruction) === 1;
    }

    protected function extractTypoNameRevisionValue(string $instruction): string
    {
        if (! preg_match('/\b(?:menjadi|jadi)\s+(.+?)(?:,?\s+(?:bagian|metadata|data|yang\s+lain)\b|\.|$)/iu', $instruction, $matches)) {
            return '';
        }

        return $this->cleanRevisionValue((string) $matches[1]);
    }

    protected function bodyContainsConfiguredNameLine(string $body): bool
    {
        return preg_match('/^\s*(?:\d+[.)]\s*)?nama(?:\s+(?:pic|pegawai)(?:\s+yang\s+benar)?)?\s*:/imu', $body) === 1;
    }

    protected function bodyHasNumberedList(string $body): bool
    {
        return preg_match('/^\s*\d+[.)]\s+\S+/mu', $body) === 1;
    }

    protected function replaceConfiguredNameLine(string $text, string $value): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(function (string $line) use ($value): string {
                return preg_replace(
                    '/^(\s*(?:\d+[.)]\s*)?nama(?:\s+(?:pic|pegawai)(?:\s+yang\s+benar)?)?\s*:\s*).+$/iu',
                    '$1'.$value,
                    $line,
                    1,
                ) ?? $line;
            })
            ->implode(PHP_EOL);
    }

    protected function applyRecipientRevision(string $instruction): void
    {
        if (! preg_match('/\b(?:penerima|yth\.?)\b/iu', $instruction)) {
            return;
        }

        $value = $this->extractRevisionValue($instruction);

        if ($value !== '') {
            $this->memoRecipient = $value;
        }
    }

    protected function applyDateRevision(string $instruction): void
    {
        if (! preg_match('/\btanggal\b/iu', $instruction)) {
            return;
        }

        if (preg_match_all('/\b(\d{1,2}\s+\p{L}+\s+\d{4})\b/u', $instruction, $matches) && ! empty($matches[1])) {
            $this->memoDate = $this->cleanRevisionValue((string) end($matches[1]));

            return;
        }

        $value = $this->extractRevisionValue($instruction);

        if ($value !== '') {
            $this->memoDate = $value;
        }
    }

    protected function applyClosingRevision(string $instruction): void
    {
        if (! preg_match('/\b(?:penutup|kalimat\s+penutup)\b/iu', $instruction)) {
            return;
        }

        $value = $this->extractRevisionValue($instruction);

        if ($value !== '') {
            $this->memoClosing = $value;
        }
    }

    protected function applySignatoryRevision(string $instruction): void
    {
        if (! preg_match('/\b(?:penandatangan|tanda\s+tangan)\b/iu', $instruction)) {
            return;
        }

        $value = $this->extractRevisionValue($instruction);

        if ($value !== '') {
            $this->memoSignatory = $value;
        }
    }

    protected function extractRevisionValue(string $instruction): string
    {
        if (preg_match('/(?:menjadi|jadi|ke|kepada|dengan|:)\s+(.+)$/iu', $instruction, $matches)) {
            return $this->cleanRevisionValue((string) $matches[1]);
        }

        return '';
    }

    protected function cleanRevisionValue(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
        $value = preg_replace('/[\.,;]\s+(?:bagian\s+(?:isi|lain|lainnya)|metadata|data\s+(?:lain|lainnya|yang\s+lain)|yang\s+lain|lainnya)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:untuk|agar|supaya)\s+(?:semua|bagian|memo|dokumen|selanjutnya)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/^(?:untuk|kepada|ke)\s+/iu', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B\"'.,;:");
    }

    protected function resolveMemoVersion(Memo $memo): ?MemoVersion
    {
        if ($memo->currentVersion) {
            return $memo->currentVersion;
        }

        return $memo->versions
            ->sortByDesc('version_number')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function activeMemoConfiguration(Memo $memo): array
    {
        $version = $this->resolveMemoVersion($memo);

        return $version?->configuration ?? $memo->configuration ?? [];
    }

    protected function applyCarbonCopyRevision(string $instruction): void
    {
        if (! preg_match('/\btembusan\b/iu', $instruction)) {
            return;
        }

        if (! preg_match('/tembusan(?:\s+(?:nomor|no\.?)\s*(\d+))?\s*[,:\-]?\s*(?:untuk\s+|kepada\s+|ke\s+)?(.+)$/iu', $instruction, $matches)) {
            return;
        }

        $item = $this->normalizeCarbonCopyItem((string) ($matches[2] ?? ''));

        if ($item === '') {
            return;
        }

        $lines = $this->carbonCopyLines();
        $normalizedItem = mb_strtolower($item);

        if (collect($lines)->contains(fn (string $line) => mb_strtolower($line) === $normalizedItem)) {
            return;
        }

        $position = isset($matches[1]) && is_numeric($matches[1])
            ? max(1, (int) $matches[1])
            : count($lines) + 1;
        $position = min($position, count($lines) + 1);

        array_splice($lines, $position - 1, 0, [$item]);

        $this->memoCarbonCopy = collect($lines)
            ->values()
            ->map(fn (string $line, int $index) => ($index + 1).'. '.$line)
            ->implode("\n");
    }

    protected function applyCarbonCopyCasingRevision(string $instruction): void
    {
        if (! preg_match('/\btembusan\b/iu', $instruction)) {
            return;
        }

        if (! preg_match('/\b(?:uppercase|huruf\s+besar|kapital)\b/iu', $instruction)) {
            return;
        }

        $lines = $this->carbonCopyLines();

        if ($lines === []) {
            return;
        }

        $target = '';

        if (preg_match('/(.+?)\s+di\s+tembusan/iu', $instruction, $matches)) {
            $target = preg_replace('/^\s*(?:ubah|ganti|perbaiki)\s+(?:nama\s+)?/iu', '', (string) $matches[1]) ?? '';
            $target = $this->normalizeCarbonCopyItem($target);
        }

        $targetLower = mb_strtolower($target);
        $changed = false;

        $lines = collect($lines)
            ->map(function (string $line) use ($targetLower, &$changed): string {
                $lineLower = mb_strtolower($line);
                $shouldTitleCase = $targetLower === ''
                    || $lineLower === $targetLower
                    || str_contains($lineLower, $targetLower);

                if (! $shouldTitleCase) {
                    return $line;
                }

                $changed = true;

                return $this->titleCaseName($line);
            })
            ->values()
            ->all();

        if (! $changed) {
            return;
        }

        $this->memoCarbonCopy = $this->formatCarbonCopyLines($lines, $this->carbonCopyIsNumbered());
    }

    /**
     * @return array<int, string>
     */
    protected function carbonCopyLines(): array
    {
        return collect(preg_split('/\R+/', $this->memoCarbonCopy) ?: [])
            ->map(fn (string $line) => $this->normalizeCarbonCopyItem($line))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeCarbonCopyItem(string $item): string
    {
        $item = preg_replace('/^\s*\d+[.)]\s*/u', '', $item) ?? $item;
        $item = preg_replace('/^(?:nomor|no\.?)\s*\d+\s*[,:\-]?\s*/iu', '', trim($item)) ?? $item;
        $item = preg_replace('/\s+/u', ' ', trim($item)) ?? $item;

        return trim($item, " \t\n\r\0\x0B.,;:");
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function formatCarbonCopyLines(array $lines, bool $numbered): string
    {
        return collect($lines)
            ->values()
            ->map(fn (string $line, int $index) => $numbered ? ($index + 1).'. '.$line : $line)
            ->implode("\n");
    }

    protected function carbonCopyIsNumbered(): bool
    {
        return collect(preg_split('/\R+/', $this->memoCarbonCopy) ?: [])
            ->filter(fn (string $line) => trim($line) !== '')
            ->contains(fn (string $line) => preg_match('/^\s*\d+[.)]\s+/u', $line) === 1);
    }

    protected function titleCaseName(string $value): string
    {
        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function applyMemoConfiguration(array $configuration): void
    {
        $this->memoNumber = (string) ($configuration['number'] ?? $this->memoNumber);
        $this->memoRecipient = (string) ($configuration['recipient'] ?? $this->memoRecipient);
        $this->memoSender = (string) ($configuration['sender'] ?? $this->memoSender);
        $this->title = (string) ($configuration['subject'] ?? $this->title);
        $this->memoDate = (string) ($configuration['date'] ?? $this->memoDate);
        $this->memoBasis = (string) ($configuration['basis'] ?? $this->memoBasis);
        $this->memoContent = (string) ($configuration['content'] ?? $this->memoContent);
        $this->memoClosing = (string) ($configuration['closing'] ?? '');
        $this->memoSignatory = (string) ($configuration['signatory'] ?? $this->memoSignatory);
        $this->memoCarbonCopy = (string) ($configuration['carbon_copy'] ?? $this->memoCarbonCopy);
        $storedPageSizeMode = (string) ($configuration['page_size_mode'] ?? '');
        $storedPageSize = (string) ($configuration['page_size'] ?? '');
        $this->memoPageSize = in_array($storedPageSizeMode, array_keys($this->memoPageSizes()), true)
            ? $storedPageSizeMode
            : (in_array($storedPageSize, array_keys($this->memoPageSizes()), true) ? $storedPageSize : $this->memoPageSize);
        $this->memoAdditionalInstruction = (string) ($configuration['additional_instruction'] ?? '');
        $this->memoRegenerateMode = 'preserve_document';
    }

    protected function resetMemoConfiguration(): void
    {
        $this->memoType = 'memo_internal';
        $this->title = '';
        $this->memoNumber = '';
        $this->memoRecipient = '';
        $this->memoSender = 'Kepala Istana Kepresidenan Yogyakarta';
        $this->memoDate = $this->defaultMemoDate();
        $this->memoBasis = '';
        $this->memoContent = '';
        $this->memoClosing = '';
        $this->memoSignatory = 'Deni Mulyana';
        $this->memoCarbonCopy = '';
        $this->memoPageSize = 'auto';
        $this->memoAdditionalInstruction = '';
        $this->memoRegenerateMode = 'preserve_document';
    }

    /**
     * @return array<string, string>
     */
    protected function memoPageSizes(): array
    {
        return [
            'auto' => 'Otomatis',
            'letter' => 'Letter (pendek)',
            'folio' => 'Folio (panjang)',
        ];
    }

    protected function resolveMemoPageSize(): string
    {
        if ($this->memoPageSize !== 'auto') {
            return $this->memoPageSize;
        }

        $body = trim($this->memoBasis."\n".$this->memoContent."\n".$this->memoAdditionalInstruction);
        $lineCount = collect(preg_split('/\R+/', $body) ?: [])
            ->filter(fn (string $line) => trim($line) !== '')
            ->count();

        return strlen($body) <= 900 && $lineCount <= 10 ? 'letter' : 'folio';
    }

    protected function defaultMemoDate(): string
    {
        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $now = now();

        return $now->format('j').' '.$months[(int) $now->format('n')].' '.$now->format('Y');
    }

    protected function addSystemMessage(string $content): void
    {
        $this->memoChatMessages[] = $this->makeSystemMessage($content);
        $this->rememberCurrentThread();
    }

    protected function makeSystemMessage(string $content): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'timestamp' => now()->format('H:i'),
        ];
    }

    protected function rememberCurrentThread(): void
    {
        $this->memoChatThreads[$this->threadKey($this->activeMemoId)] = $this->memoChatMessages;
        $this->persistActiveMemoThread();
    }

    protected function threadKey(?int $memoId = null): string
    {
        return $memoId ? 'memo-'.$memoId : self::DRAFT_THREAD_KEY;
    }

    protected function persistActiveMemoThread(): void
    {
        if (! $this->activeMemoId) {
            return;
        }

        $mergedThread = DB::transaction(function () {
            $memo = Memo::with('versions')
                ->lockForUpdate()
                ->where('user_id', Auth::id())
                ->where('id', $this->activeMemoId)
                ->first();

            if (! $memo) {
                return null;
            }

            $storedThread = $this->threadWithRevisionInstructions(
                $this->normalizeStoredThread($memo->chat_messages ?? []),
                $memo,
            );
            $currentThread = $this->threadWithRevisionInstructions(
                $this->normalizeStoredThread($this->memoChatMessages),
                $memo,
            );
            $mergedThread = $this->mergeMemoChatThreads($storedThread, $currentThread);

            Memo::withoutTimestamps(fn () => Memo::where('id', $this->activeMemoId)
                ->where('user_id', Auth::id())
                ->update(['chat_messages' => $mergedThread]));

            return $mergedThread;
        });

        if ($mergedThread === null) {
            return;
        }

        $this->memoChatMessages = $mergedThread;
        $this->memoChatThreads[$this->threadKey($this->activeMemoId)] = $mergedThread;
    }

    protected function normalizeStoredThread(array $messages): array
    {
        return collect($messages)
            ->filter(fn ($message) => is_array($message))
            ->map(fn (array $message) => [
                'role' => in_array(($message['role'] ?? null), ['assistant', 'user'], true) ? $message['role'] : 'assistant',
                'content' => (string) ($message['content'] ?? ''),
                'timestamp' => (string) ($message['timestamp'] ?? now()->format('H:i')),
            ])
            ->filter(fn (array $message) => trim($message['content']) !== '')
            ->reject(fn (array $message) => $this->isPassiveMemoLoadMessage($message))
            ->values()
            ->all();
    }

    protected function isPassiveMemoLoadMessage(array $message): bool
    {
        if (($message['role'] ?? null) !== 'assistant') {
            return false;
        }

        $content = trim((string) ($message['content'] ?? ''));

        return (bool) preg_match('/^Memo\s+"[^"]+"\s+(?:berhasil\s+)?dimuat\.?(?:\s+Anda bisa meminta revisi atau generate ulang\.)?$/iu', $content);
    }

    protected function mergeMemoChatThreads(array $primaryThread, array $secondaryThread): array
    {
        $merged = [];
        $seen = [];

        foreach ([...$primaryThread, ...$secondaryThread] as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $role = in_array(($message['role'] ?? null), ['assistant', 'user'], true) ? $message['role'] : 'assistant';
            $key = $role.'|'.mb_strtolower(preg_replace('/\s+/', ' ', $content));
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

    protected function threadWithRevisionInstructions(array $messages, Memo $memo): array
    {
        $versions = $memo->relationLoaded('versions') ? $memo->versions : $memo->versions()->get();
        $existingUserMessages = collect($messages)
            ->filter(fn (array $message) => ($message['role'] ?? null) === 'user')
            ->map(fn (array $message) => trim((string) ($message['content'] ?? '')))
            ->filter()
            ->map(fn (string $content) => mb_strtolower(preg_replace('/\s+/', ' ', $content)))
            ->all();

        foreach ($versions->sortBy('version_number') as $version) {
            $instruction = trim((string) $version->revision_instruction);
            if ($instruction === '') {
                continue;
            }

            $instructionKey = mb_strtolower(preg_replace('/\s+/', ' ', $instruction));
            if (in_array($instructionKey, $existingUserMessages, true)) {
                continue;
            }

            $timestamp = $version->created_at?->format('H:i') ?: now()->format('H:i');
            $messages[] = [
                'role' => 'user',
                'content' => $instruction,
                'timestamp' => $timestamp,
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => "Revisi memo \"{$memo->title}\" tersimpan sebagai {$version->label}.",
                'timestamp' => $timestamp,
            ];
            $existingUserMessages[] = $instructionKey;
        }

        return $messages;
    }

    protected function enforceRateLimit(string $action, int $maxAttempts, int $decaySeconds = 60, ?string $message = null): void
    {
        $key = $this->rateLimitKey($action);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'rate_limit' => $message ?? 'Terlalu banyak permintaan. Silakan coba lagi sebentar.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    protected function rateLimitKey(string $action): string
    {
        $userId = Auth::id();
        $ip = request()?->ip() ?? 'unknown';
        $userPart = $userId ? 'user-'.$userId : 'guest';

        return implode(':', [static::class, $action, $userPart, $ip]);
    }
}
