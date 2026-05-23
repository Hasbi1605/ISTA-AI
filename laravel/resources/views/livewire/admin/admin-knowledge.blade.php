@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = function (int $value, int $total): string {
        if ($total <= 0) {
            return '0%';
        }

        return ((int) round(($value / $total) * 100)) . '%';
    };
    $fileTypeMeta = function ($doc): array {
        $mime = (string) ($doc?->mime_type ?? '');
        $extension = strtolower((string) pathinfo((string) ($doc?->original_name ?? ''), PATHINFO_EXTENSION));
        $type = match (true) {
            $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
            in_array($mime, ['text/csv', 'text/plain', 'application/csv'], true) || $extension === 'csv' => 'csv',
            in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'], true) || in_array($extension, ['xls', 'xlsx'], true) => 'xlsx',
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $extension === 'docx' => 'docx',
            $extension === 'txt' => 'txt',
            in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'img'], true) => 'image',
            default => 'file',
        };

        return [
            'key' => $type,
            'label' => match ($type) {
                'pdf' => 'PDF',
                'csv' => 'CSV',
                'xlsx' => 'XLSX',
                'docx' => 'DOCX',
                'txt' => 'TXT',
                'image' => 'IMG',
                default => strtoupper($extension ?: 'FILE'),
            },
        ];
    };
    $statusMeta = function (?string $status): array {
        return match ($status) {
            'active' => ['label' => 'Ready', 'tone' => 'success'],
            'processing' => ['label' => 'Processing', 'tone' => 'warning'],
            'draft' => ['label' => 'Draft', 'tone' => 'neutral'],
            'error' => ['label' => 'Failed', 'tone' => 'danger'],
            'archived' => ['label' => 'Archived', 'tone' => 'neutral'],
            default => ['label' => ucfirst((string) ($status ?: 'Unknown')), 'tone' => 'neutral'],
        };
    };
    $initials = function (?string $name, ?string $email = null): string {
        $base = trim((string) ($name ?: $email ?: 'Admin'));
        $parts = preg_split('/\s+/', $base) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_substr($part, 0, 1);
        }

        return mb_strtoupper($letters ?: 'A');
    };
    $pipelineMeta = function ($doc): array {
        $status = (string) ($doc?->status ?? 'draft');
        $chunks = (int) ($doc?->chunks?->chunk_count ?? 0);
        $progress = match ($status) {
            'active' => 100,
            'processing' => 62,
            'draft' => 24,
            'error' => 100,
            'archived' => 100,
            default => 12,
        };
        $tone = match ($status) {
            'active' => 'success',
            'processing' => 'warning',
            'error' => 'danger',
            default => 'neutral',
        };
        $stageState = function (string $stage) use ($status, $chunks): string {
            if ($stage === 'uploaded') {
                return 'done';
            }

            if ($status === 'error') {
                return 'failed';
            }

            if ($stage === 'parsed') {
                return in_array($status, ['active', 'archived'], true) ? 'done' : ($status === 'processing' ? 'active' : 'pending');
            }

            if ($stage === 'indexed') {
                return $chunks > 0 ? 'done' : ($status === 'processing' ? 'active' : 'pending');
            }

            if ($stage === 'ready') {
                return $status === 'active' ? 'done' : 'pending';
            }

            return 'pending';
        };

        return [
            'progress' => $progress,
            'tone' => $tone,
            'chunks' => $chunks,
            'summary' => match ($status) {
                'active' => $chunks > 0 ? 'Indexed' : 'Ready tanpa chunk',
                'processing' => 'Parsing / indexing',
                'error' => 'Perlu dicek',
                'archived' => 'Archived',
                default => 'Menunggu proses',
            },
            'stages' => [
                ['label' => 'Uploaded', 'state' => $stageState('uploaded')],
                ['label' => 'Parsed', 'state' => $stageState('parsed')],
                ['label' => 'Indexed', 'state' => $stageState('indexed')],
                ['label' => 'Ready', 'state' => $stageState('ready')],
            ],
        ];
    };
    $uploadedFileName = is_object($file) && method_exists($file, 'getClientOriginalName')
        ? $file->getClientOriginalName()
        : 'Pilih file PDF, DOCX, XLSX, atau CSV';
    $uploadHasFile = is_object($file);
    $uploadHasSource = filled($sourceId) || trim((string) $newSourceName) !== '';
    $uploadCanSubmit = $uploadHasFile && $uploadHasSource;
    $uploadErrorMessages = collect(['upload', 'file', 'newSourceName', 'sourceId', 'title', 'notes'])
        ->flatMap(fn (string $field) => $errors->get($field))
        ->unique()
        ->values();

    $totalDocs = array_sum($statusCounts);
    $activeCount = (int) ($statusCounts['active'] ?? 0);
    $processingCount = (int) (($statusCounts['draft'] ?? 0) + ($statusCounts['processing'] ?? 0));
    $errorCount = (int) ($statusCounts['error'] ?? 0);
    $archivedCount = (int) ($statusCounts['archived'] ?? 0);

    $knowledgeCards = [
        [
            'label' => 'Total knowledge',
            'value' => $totalDocs,
            'description' => $formatInt($archivedCount) . ' archived',
            'tone' => 'primary',
            'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253',
        ],
        [
            'label' => 'Ready',
            'value' => $activeCount,
            'description' => $formatPct($activeCount, $totalDocs) . ' siap dipakai',
            'tone' => 'success',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Processing',
            'value' => $processingCount,
            'description' => $formatPct($processingCount, $totalDocs) . ' sedang disiapkan',
            'tone' => 'warning',
            'icon' => 'M12 6v6l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Failed',
            'value' => $errorCount,
            'description' => $formatPct($errorCount, $totalDocs) . ' perlu dicek',
            'tone' => 'danger',
            'icon' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        ],
    ];
@endphp

<div class="admin-knowledge-page">
    @if ($shouldPollKnowledgePipeline)
        <div wire:poll.5s="refreshKnowledgePipeline" class="admin-knowledge-pipeline-poll hidden" aria-hidden="true"></div>
    @endif

    <div class="admin-knowledge-hero">
        <div class="max-w-2xl">
            <p class="admin-knowledge-eyebrow">Knowledge</p>
            <h2 class="admin-knowledge-title">Knowledge Base Internal</h2>
            <p class="admin-knowledge-description">
                Kelola dokumen internal sebagai pipeline AI: uploaded, parsed, indexed, lalu ready.
            </p>
        </div>
        <div class="admin-knowledge-hero__actions">
            <x-admin.badge tone="neutral" class="admin-knowledge-readonly">Admin only</x-admin.badge>
        </div>
    </div>

    @if (session('knowledge_status'))
        <div class="admin-knowledge-alert">
            {{ session('knowledge_status') }}
        </div>
    @endif

    @if ($hasPendingKnowledgeDocuments)
        <div class="admin-knowledge-pipeline-sync" role="status" aria-live="polite">
            <span class="admin-knowledge-pipeline-sync__spinner" aria-hidden="true"></span>
            <span>
                <strong>{{ $formatInt($pendingKnowledgeCount) }} dokumen sedang diproses</strong>
                <em>Status pipeline akan tersinkron otomatis.</em>
            </span>
        </div>
    @endif

    <div class="admin-knowledge-kpi-grid">
        @foreach ($knowledgeCards as $card)
            <article class="admin-knowledge-kpi-card admin-knowledge-kpi-card--{{ $card['tone'] }}">
                <div class="admin-knowledge-kpi-card__header">
                    <span class="admin-knowledge-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-knowledge-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-knowledge-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-knowledge-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <div class="admin-knowledge-main-stack admin-knowledge-main-stack--full">
            <section class="admin-knowledge-filter-panel admin-section">
                <div class="admin-knowledge-filter-panel__header">
                    <h3>Filter</h3>
                    <button type="button" wire:click="resetFilters" class="admin-knowledge-reset-button">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                        </svg>
                        Reset
                    </button>
                </div>

                <div class="admin-knowledge-filter-grid">
                    <label class="admin-knowledge-filter">
                        <span>Cari knowledge</span>
                        <input type="search"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Contoh: SOP HR"
                               class="admin-knowledge-control" />
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>Status</span>
                        <select wire:model.live="status" class="admin-knowledge-control">
                            <option value="">Semua</option>
                            @foreach ($statusOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>Source</span>
                        <select wire:model.live="sourceFilter" class="admin-knowledge-control">
                            <option value="">Semua</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <section class="admin-knowledge-table-panel admin-section">
                <header class="admin-knowledge-table-panel__header">
                    <div>
                        <h3>Dokumen Knowledge</h3>
                        <p>{{ $documentsPerPage }} baris per halaman pada filter aktif.</p>
                    </div>
                    <div class="admin-knowledge-table-panel__actions">
                        <button type="button" wire:click="openUploadModal" class="admin-knowledge-primary-button">
                            Upload Knowledge
                        </button>
                    </div>
                </header>

                <div class="admin-knowledge-table-panel__body">
                    @if ($documents->isEmpty())
                        <x-admin.empty-state title="Belum ada knowledge" description="Upload dokumen internal agar bisa diproses dan dipakai AI saat relevan." />
                    @else
                        <x-admin.table
                            class="admin-knowledge-table"
                            :columns="[
                                ['key' => 'file', 'label' => 'File', 'width' => '28%'],
                                ['key' => 'source', 'label' => 'Source', 'width' => '14%'],
                                ['key' => 'admin', 'label' => 'Uploader', 'width' => '18%'],
                                ['key' => 'status', 'label' => 'Status', 'width' => '11%'],
                                ['key' => 'pipeline', 'label' => 'Pipeline', 'width' => '17%'],
                                ['key' => 'chunks', 'label' => 'Chunks', 'align' => 'center', 'width' => '6%'],
                                ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '8rem'],
                            ]">
                            @foreach ($documents as $doc)
                                @php
                                    $typeMeta = $fileTypeMeta($doc);
                                    $status = $statusMeta($doc->status);
                                    $pipeline = $pipelineMeta($doc);
                                    $canActivate = $doc->isActivatable();
                                    $canArchive = $doc->isArchivable();
                                    $canReprocess = $doc->isReprocessable();
                                    $isRecentlyUploadedPending = $recentUploadDocumentId === (int) $doc->id
                                        && in_array($doc->status, ['draft', 'processing'], true);
                                    $actionLabel = \Illuminate\Support\Str::limit((string) $doc->title, 56, '...');
                                @endphp
                                <tr @class(['admin-knowledge-table__row--recent' => $isRecentlyUploadedPending])>
                                    <td class="admin-table__td">
                                        <div class="admin-documents-file-cell">
                                            <x-admin.document-icon :type="$typeMeta['key']" :label="$typeMeta['label']" />
                                            <div class="min-w-0">
                                                <span class="admin-knowledge-file-name" title="{{ $doc->title }}">{{ \Illuminate\Support\Str::limit((string) $doc->title, 48, '...') }}</span>
                                                <span class="admin-knowledge-file-meta">{{ \Illuminate\Support\Str::limit((string) $doc->original_name, 48, '...') }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="admin-table__td">
                                        <span class="admin-knowledge-source">{{ $doc->source?->name ?? 'Default' }}</span>
                                    </td>
                                    <td class="admin-table__td">
                                        <div class="admin-documents-user-row">
                                            <span class="admin-documents-avatar" aria-hidden="true">{{ $initials($doc->uploader?->name, $doc->uploader?->email) }}</span>
                                            <div class="admin-documents-user-cell">
                                                <span class="admin-documents-user-cell__name">{{ $doc->uploader?->name ?? 'Sistem' }}</span>
                                                <span class="admin-documents-user-cell__email">{{ $doc->uploader?->email ?? '-' }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="admin-table__td">
                                        <span class="admin-status-chip admin-status-chip--{{ $status['tone'] }}">
                                            <span aria-hidden="true"></span>
                                            {{ $status['label'] }}
                                        </span>
                                        @if ($doc->error_code)
                                            <p class="admin-knowledge-error-code">{{ $doc->error_code }}</p>
                                        @endif
                                    </td>
                                    <td class="admin-table__td">
                                        <div class="admin-knowledge-pipeline">
                                            <div class="admin-knowledge-pipeline__track" aria-hidden="true">
                                                <span class="admin-knowledge-pipeline__bar admin-knowledge-pipeline__bar--{{ $pipeline['tone'] }}" style="width: {{ $pipeline['progress'] }}%"></span>
                                            </div>
                                            <span>{{ $pipeline['summary'] }}</span>
                                        </div>
                                    </td>
                                    <td class="admin-table__td" data-align="center">
                                        <span class="admin-documents-number">{{ number_format($pipeline['chunks']) }}</span>
                                    </td>
                                    <td class="admin-table__td" data-align="right">
                                        <div class="admin-knowledge-action-group">
                                            @if ($canActivate)
                                                <button type="button"
                                                        wire:click="activate({{ $doc->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="activate({{ $doc->id }})"
                                                        class="admin-knowledge-action admin-knowledge-action--success"
                                                        title="Aktifkan"
                                                        aria-label="Aktifkan {{ $actionLabel }}">
                                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l5.25 5.25L19.5 6.75"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            @if ($canArchive)
                                                <button type="button"
                                                        wire:click="archive({{ $doc->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="archive({{ $doc->id }})"
                                                        class="admin-knowledge-action admin-knowledge-action--warning"
                                                        title="Arsip"
                                                        aria-label="Arsip {{ $actionLabel }}">
                                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M20.25 7.5v10.25a2 2 0 01-2 2H5.75a2 2 0 01-2-2V7.5m16.5 0H3.75m16.5 0l-1.4-3.1A2 2 0 0017.02 3.25H6.98a2 2 0 00-1.83 1.15L3.75 7.5m5 4.25h6.5"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            @if ($canReprocess)
                                                <button type="button"
                                                        wire:click="reprocess({{ $doc->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="reprocess({{ $doc->id }})"
                                                        class="admin-knowledge-action"
                                                        title="Proses ulang"
                                                        aria-label="Proses ulang {{ $actionLabel }}">
                                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            <button type="button"
                                                    wire:click="delete({{ $doc->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="delete({{ $doc->id }})"
                                                    wire:confirm="Yakin hapus knowledge ini? Vector akan dihapus juga."
                                                    class="admin-knowledge-action admin-knowledge-action--danger"
                                                    title="Hapus"
                                                    aria-label="Hapus {{ $actionLabel }}">
                                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M19 7l-.86 11.14A2 2 0 0116.15 20H7.85a2 2 0 01-1.99-1.86L5 7m4 4v5m6-5v5M10 7V4.75A1.75 1.75 0 0111.75 3h.5A1.75 1.75 0 0114 4.75V7M4 7h16"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </x-admin.table>

                        <div class="admin-documents-table-footer">
                            <div class="admin-documents-pagination admin-knowledge-pagination" wire:key="admin-knowledge-pagination-{{ $documents->currentPage() }}-{{ $documents->lastPage() }}-{{ $documents->total() }}-{{ $documents->firstItem() }}-{{ $documents->lastItem() }}">
                                {{ $documents->links('admin.pagination') }}
                            </div>
                        </div>
                    @endif
                </div>
            </section>
    </div>

    @if ($showUploadModal)
        <div class="admin-knowledge-upload-modal"
             x-data="{
                fileBusy: false,
                uploadBusy: @js($isUploading),
                get isBusy() {
                    return this.fileBusy || this.uploadBusy;
                },
                submitKnowledgeUpload() {
                    if (this.uploadBusy) {
                        return;
                    }

                    this.uploadBusy = true;

                    try {
                        Promise.resolve($wire.$call('submitKnowledgeUpload'))
                            .catch(() => {})
                            .finally(() => {
                                this.uploadBusy = false;
                            });
                    } catch (error) {
                        this.uploadBusy = false;
                    }
                },
             }"
             x-on:livewire-upload-start="fileBusy = true"
             x-on:livewire-upload-finish="fileBusy = false"
             x-on:livewire-upload-error="fileBusy = false"
             x-on:livewire-upload-cancel="fileBusy = false"
             x-on:knowledge-upload-finished.window="uploadBusy = false"
             x-bind:aria-busy="isBusy ? 'true' : 'false'"
             role="dialog"
             aria-modal="true"
             aria-labelledby="knowledge-upload-title">
            <button type="button"
                    class="admin-knowledge-upload-modal__backdrop"
                    wire:click="closeUploadModal"
                    x-bind:disabled="isBusy"
                    wire:loading.attr="disabled"
                    wire:target="submitKnowledgeUpload,file"
                    aria-label="Tutup upload knowledge"></button>

            <section class="admin-knowledge-upload-modal__panel">
                <header class="admin-knowledge-upload-modal__header">
                    <div>
                        <p>Knowledge upload</p>
                        <h3 id="knowledge-upload-title">Upload knowledge</h3>
                    </div>
                    <button type="button"
                            wire:click="closeUploadModal"
                            x-bind:disabled="isBusy"
                            wire:loading.attr="disabled"
                            wire:target="submitKnowledgeUpload,file"
                            class="admin-knowledge-upload-modal__close"
                            aria-label="Tutup upload knowledge">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <form x-on:submit.prevent="submitKnowledgeUpload()"
                      wire:loading.class="admin-knowledge-upload-form--busy"
                      x-bind:class="{ 'admin-knowledge-upload-form--busy': isBusy }"
                      wire:target="submitKnowledgeUpload,file"
                      class="admin-knowledge-upload-form">
                    @if ($uploadErrorMessages->isNotEmpty())
                        <div class="admin-knowledge-upload-validation" role="alert">
                            <strong>Upload knowledge belum bisa diproses.</strong>
                            <ul role="list">
                                @foreach ($uploadErrorMessages as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <label class="admin-knowledge-filter">
                        <span>Judul opsional</span>
                        <input type="text"
                               wire:model.defer="title"
                               x-bind:disabled="isBusy"
                               wire:loading.attr="disabled"
                               wire:target="submitKnowledgeUpload,file"
                               placeholder="Contoh: SOP Penerimaan Tamu"
                               class="admin-knowledge-control" />
                        @error('title') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <div class="admin-knowledge-upload-grid">
                        <label class="admin-knowledge-filter">
                            <span>Source existing</span>
                            <select wire:model.live="sourceId"
                                    x-bind:disabled="isBusy"
                                    wire:loading.attr="disabled"
                                    wire:target="submitKnowledgeUpload,file"
                                    class="admin-knowledge-control">
                                <option value="">Pilih source</option>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                            @error('sourceId') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="admin-knowledge-filter">
                            <span>Atau source baru</span>
                            <input type="text"
                                   wire:model.live.debounce.300ms="newSourceName"
                                   x-bind:disabled="isBusy"
                                   wire:loading.attr="disabled"
                                   wire:target="submitKnowledgeUpload,file"
                                   placeholder="Contoh: Aturan internal"
                                   class="admin-knowledge-control" />
                            @error('newSourceName') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <p @class(['admin-knowledge-upload-source-note', 'admin-knowledge-upload-source-note--error' => $errors->has('newSourceName') || $errors->has('sourceId')])>
                        Pilih source existing atau isi source baru.
                    </p>

                    <label class="admin-knowledge-filter">
                        <span>Catatan internal</span>
                        <textarea wire:model.defer="notes"
                                  x-bind:disabled="isBusy"
                                  wire:loading.attr="disabled"
                                  wire:target="submitKnowledgeUpload,file"
                                  rows="3"
                                  placeholder="Konteks singkat untuk admin lain"
                                  class="admin-knowledge-control admin-knowledge-control--textarea"></textarea>
                        @error('notes') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="admin-knowledge-dropzone @error('file') admin-knowledge-dropzone--error @enderror"
                           wire:loading.class="admin-knowledge-dropzone--disabled"
                           x-bind:class="{ 'admin-knowledge-dropzone--disabled': isBusy }"
                           wire:target="submitKnowledgeUpload,file">
                        <input type="file"
                               wire:model="file"
                               x-bind:disabled="isBusy"
                               wire:loading.attr="disabled"
                               wire:target="submitKnowledgeUpload,file"
                               accept=".pdf,.docx,.xlsx,.csv"
                               class="sr-only" />
                        <span>File knowledge <em>Wajib</em></span>
                        <strong>{{ $uploadedFileName }}</strong>
                        <em>Format: {{ implode(', ', $allowedExtensions) }}. File akan masuk pipeline processing.</em>
                        @error('file') <b>{{ $message }}</b> @enderror
                    </label>

                    <div wire:loading.flex wire:target="file" x-bind:class="{ 'admin-knowledge-upload-progress--visible': fileBusy }" class="admin-knowledge-upload-progress" role="status" aria-live="polite">
                        <span class="admin-knowledge-upload-progress__spinner" aria-hidden="true"></span>
                        <span>
                            <strong>Mengirim file knowledge...</strong>
                            <em>Biarkan modal terbuka sampai file selesai diterima.</em>
                        </span>
                    </div>

                    <div wire:loading.flex wire:target="submitKnowledgeUpload" x-bind:class="{ 'admin-knowledge-upload-progress--visible': uploadBusy }" class="admin-knowledge-upload-progress admin-knowledge-upload-progress--processing" role="status" aria-live="polite">
                        <span class="admin-knowledge-upload-progress__spinner" aria-hidden="true"></span>
                        <span>
                            <strong>Menjadwalkan processing...</strong>
                            <em>Dokumen akan muncul sebagai Processing setelah berhasil masuk antrean.</em>
                        </span>
                    </div>

                    <footer class="admin-knowledge-upload-modal__footer">
                        <button type="button"
                                wire:click="closeUploadModal"
                                x-bind:disabled="isBusy"
                                wire:loading.attr="disabled"
                                wire:target="submitKnowledgeUpload,file"
                                class="admin-knowledge-secondary-button">Batal</button>
                        <button type="submit"
                                class="admin-knowledge-primary-button"
                                x-bind:disabled="isBusy"
                                wire:loading.attr="disabled"
                                wire:target="submitKnowledgeUpload,file">
                            <span x-show="! isBusy">Upload Knowledge</span>
                            <span x-show="fileBusy && ! uploadBusy" x-cloak class="admin-knowledge-upload-button__loading">
                                <span class="admin-knowledge-upload-button__spinner" aria-hidden="true"></span>
                                Mengirim...
                            </span>
                            <span x-show="uploadBusy" x-cloak class="admin-knowledge-upload-button__loading">
                                <span class="admin-knowledge-upload-button__spinner" aria-hidden="true"></span>
                                Processing...
                            </span>
                        </button>
                        @unless ($uploadCanSubmit)
                            <p class="admin-knowledge-upload-submit-note">Pilih source dan file untuk mengaktifkan tombol upload.</p>
                        @endunless
                    </footer>
                </form>
            </section>
        </div>
    @endif
</div>
