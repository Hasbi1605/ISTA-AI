@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = function (int $value, int $total): string {
        if ($total <= 0) {
            return '0.0%';
        }

        return number_format(($value / $total) * 100, 1, '.', '') . '%';
    };
    $formatBytes = function (int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    };
    $fileTypeMeta = function ($doc) {
        $mime = (string) ($doc?->mime_type ?? '');
        $extension = strtolower((string) pathinfo((string) ($doc?->original_name ?? ''), PATHINFO_EXTENSION));
        $type = match (true) {
            $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
            in_array($mime, ['text/csv', 'text/plain', 'application/csv'], true) || $extension === 'csv' => 'csv',
            in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'], true) || in_array($extension, ['xls', 'xlsx'], true) => 'xlsx',
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $extension === 'docx' => 'docx',
            default => 'file',
        };

        return [
            'key' => $type,
            'label' => match ($type) {
                'pdf' => 'PDF',
                'csv' => 'CSV',
                'xlsx' => 'XLSX',
                'docx' => 'DOCX',
                default => strtoupper($extension ?: 'FILE'),
            },
        ];
    };
    $statusMeta = function (?string $status): array {
        return match ($status) {
            'ready' => ['label' => 'Ready', 'tone' => 'success'],
            'processing' => ['label' => 'Processing', 'tone' => 'warning'],
            'pending' => ['label' => 'Pending', 'tone' => 'neutral'],
            'error' => ['label' => 'Failed', 'tone' => 'danger'],
            default => ['label' => ucfirst((string) ($status ?: 'Unknown')), 'tone' => 'neutral'],
        };
    };
    $stageState = function (string $status, string $previewStatus, string $stage): string {
        if ($stage === 'uploaded') {
            return 'done';
        }

        if ($status === 'error' || $previewStatus === 'failed') {
            return 'failed';
        }

        if ($stage === 'parsed') {
            if ($status === 'ready' || $previewStatus === 'ready') {
                return 'done';
            }

            return $status === 'processing' ? 'active' : 'pending';
        }

        if ($stage === 'indexed') {
            if ($status === 'ready') {
                return 'done';
            }

            return $status === 'processing' ? 'active' : 'pending';
        }

        if ($stage === 'ready') {
            return $status === 'ready' ? 'done' : 'pending';
        }

        return 'pending';
    };
    $pipelineMeta = function ($doc) use ($stageState): array {
        $status = (string) ($doc?->status ?? 'pending');
        $previewStatus = (string) ($doc?->preview_status ?? 'pending');
        $chunks = (int) ($doc?->chunks_count ?? 0);
        $progress = match ($status) {
            'ready' => 100,
            'processing' => $previewStatus === 'ready' ? 72 : 55,
            'pending' => 25,
            'error' => 100,
            default => 15,
        };
        $parseStatus = match (true) {
            $status === 'error' || $previewStatus === 'failed' => 'Failed',
            $status === 'ready' || $previewStatus === 'ready' => 'Parsed',
            $status === 'processing' => 'Parsing',
            default => 'Queued',
        };
        $embeddingStatus = match ($status) {
            'ready' => 'Indexed',
            'processing' => 'Indexing',
            'error' => 'Failed',
            default => 'Waiting',
        };

        return [
            'progress' => $progress,
            'tone' => $status === 'error' ? 'danger' : ($status === 'ready' ? 'success' : 'warning'),
            'parse_status' => $parseStatus,
            'embedding_status' => $embeddingStatus,
            'chunk_count' => $chunks,
            'stages' => [
                ['key' => 'uploaded', 'label' => 'Uploaded', 'state' => $stageState($status, $previewStatus, 'uploaded')],
                ['key' => 'parsed', 'label' => 'Parsed', 'state' => $stageState($status, $previewStatus, 'parsed')],
                ['key' => 'indexed', 'label' => 'Indexed', 'state' => $stageState($status, $previewStatus, 'indexed')],
                ['key' => 'ready', 'label' => 'Ready', 'state' => $stageState($status, $previewStatus, 'ready')],
            ],
        ];
    };

    $totalDocs = array_sum($statusCounts);
    $readyCount = (int) ($statusCounts['ready'] ?? 0);
    $processingCount = (int) (($statusCounts['pending'] ?? 0) + ($statusCounts['processing'] ?? 0));
    $failedCount = (int) ($statusCounts['error'] ?? 0);
    $sizeLabel = $formatBytes((int) $totalSizeBytes);

    $documentCards = [
        [
            'label' => 'Total Dokumen',
            'value' => $totalDocs,
            'description' => $sizeLabel . ' total ukuran',
            'tone' => 'primary',
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.5L19 9.5V19a2 2 0 01-2 2z',
        ],
        [
            'label' => 'Ready',
            'value' => $readyCount,
            'description' => $formatPct($readyCount, $totalDocs) . ' siap dipakai',
            'tone' => 'success',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Diproses',
            'value' => $processingCount,
            'description' => $formatPct($processingCount, $totalDocs) . ' pending / processing',
            'tone' => 'warning',
            'icon' => 'M12 6v6l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Failed',
            'value' => $failedCount,
            'description' => $formatPct($failedCount, $totalDocs) . ' perlu dicek',
            'tone' => 'danger',
            'icon' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        ],
    ];
@endphp

<div class="admin-documents-page">
    <div class="admin-documents-hero">
        <div class="max-w-2xl">
            <p class="admin-documents-eyebrow">Monitoring</p>
            <h2 class="admin-documents-title">Dokumen User</h2>
            <p class="admin-documents-description">
                Pantau pipeline dokumen dari upload, parsing, indexing, sampai siap dipakai AI.
            </p>
        </div>
        <x-admin.badge tone="neutral" class="admin-documents-readonly">Read-only</x-admin.badge>
    </div>

    <div class="admin-documents-kpi-grid">
        @foreach ($documentCards as $card)
            <article class="admin-documents-kpi-card admin-documents-kpi-card--{{ $card['tone'] }}">
                <div class="admin-documents-kpi-card__header">
                    <span class="admin-documents-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-documents-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-documents-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-documents-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <section class="admin-documents-filter-panel admin-section">
        <div class="admin-documents-filter-panel__header">
            <h3>Filter</h3>
            <div class="admin-documents-reset-group">
                <button type="button" wire:click="resetFilters" class="admin-documents-reset-button">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                    </svg>
                    Reset
                </button>
            </div>
        </div>

        <div class="admin-documents-filter-grid">
            <label class="admin-documents-filter">
                <span>Cari File</span>
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Contoh: laporan.pdf"
                       class="admin-documents-control" />
            </label>

            <label class="admin-documents-filter">
                <span>Tipe</span>
                <select wire:model.live="type" class="admin-documents-control">
                    <option value="">Semua</option>
                    @foreach ($typeOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-documents-filter">
                <span>Status</span>
                <select wire:model.live="status" class="admin-documents-control">
                    <option value="">Semua</option>
                    @foreach ($statusOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-documents-filter">
                <span>User</span>
                <select wire:model.live="ownerId" class="admin-documents-control">
                    <option value="">Semua</option>
                    @foreach ($ownerOptions as $owner)
                        <option value="{{ $owner->id }}">{{ $owner->name }} - {{ $owner->email }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-documents-filter">
                <span>Tanggal Mulai</span>
                <input type="date" wire:model.live="startDate" class="admin-documents-control" />
            </label>

            <label class="admin-documents-filter">
                <span>Tanggal Akhir</span>
                <input type="date" wire:model.live="endDate" class="admin-documents-control" />
            </label>
        </div>
    </section>

    <div class="admin-documents-content-grid">
        <section class="admin-documents-table-panel admin-section">
            <header class="admin-documents-table-panel__header">
                <div>
                    <h3>Pipeline Dokumen</h3>
                    <p>Menampilkan {{ $documentsPerPage }} dokumen per halaman pada filter aktif.</p>
                </div>
            </header>

            <div class="admin-documents-table-panel__body">
                @if ($documents->isEmpty())
                    <x-admin.empty-state
                        title="Belum ada dokumen"
                        description="Tidak ada dokumen yang cocok dengan filter saat ini." />
                @else
                    <x-admin.table
                        class="admin-documents-table"
                        :columns="[
                            ['key' => 'file', 'label' => 'File', 'width' => '25%'],
                            ['key' => 'owner', 'label' => 'Owner', 'width' => '17%'],
                            ['key' => 'status', 'label' => 'Status', 'width' => '12%'],
                            ['key' => 'pipeline', 'label' => 'Pipeline', 'width' => '20%'],
                            ['key' => 'size', 'label' => 'Size', 'align' => 'right', 'width' => '8%'],
                            ['key' => 'uploaded', 'label' => 'Uploaded', 'align' => 'right', 'width' => '10%'],
                            ['key' => 'action', 'label' => '', 'align' => 'right', 'width' => '8%'],
                        ]">
                        @foreach ($documents as $doc)
                            @php
                                $typeMeta = $fileTypeMeta($doc);
                                $status = $statusMeta($doc->status);
                                $pipeline = $pipelineMeta($doc);
                            @endphp
                            <tr>
                                <td class="admin-table__td">
                                    <div class="admin-documents-file-cell">
                                        <span class="admin-documents-file-icon admin-documents-file-icon--{{ $typeMeta['key'] }}" aria-hidden="true">
                                            {{ $typeMeta['label'] }}
                                        </span>
                                        <div class="min-w-0">
                                            <span class="admin-documents-file-cell__name" title="{{ $doc->original_name }}">
                                                {{ \Illuminate\Support\Str::limit((string) $doc->original_name, 54, '...') }}
                                            </span>
                                            <span class="admin-documents-file-cell__meta">{{ $doc->mime_type ?? 'unknown' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <div class="admin-documents-user-cell">
                                        <span class="admin-documents-user-cell__name">{{ $doc->user?->name ?? 'Sistem' }}</span>
                                        <span class="admin-documents-user-cell__email">{{ $doc->user?->email ?? '-' }}</span>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-documents-status-chip admin-documents-status-chip--{{ $status['tone'] }}">
                                        {{ $status['label'] }}
                                    </span>
                                </td>
                                <td class="admin-table__td">
                                    <div class="admin-documents-pipeline">
                                        <div class="admin-documents-pipeline__track" aria-hidden="true">
                                            <span class="admin-documents-pipeline__bar admin-documents-pipeline__bar--{{ $pipeline['tone'] }}" style="width: {{ $pipeline['progress'] }}%"></span>
                                        </div>
                                        <div class="admin-documents-pipeline__meta">
                                            <span>{{ $pipeline['parse_status'] }}</span>
                                            <span>{{ $pipeline['embedding_status'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="admin-documents-number">{{ $doc->formatted_size }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="admin-documents-muted" title="{{ $doc->created_at?->toDateTimeString() }}">{{ $doc->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <button type="button" wire:click="showDetail({{ $doc->id }})" class="admin-documents-detail-button">
                                        Detail
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>

                    @if ($documents->hasPages())
                        <div class="admin-documents-pagination">
                            {{ $documents->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </section>

        <div class="admin-documents-side-grid">
            <section class="admin-documents-distribution-panel admin-section">
                <header class="admin-documents-distribution-panel__header">
                    <div>
                        <h3>Distribusi Tipe</h3>
                        <p>Berdasarkan filter aktif.</p>
                    </div>
                </header>

                <div class="admin-documents-distribution-panel__body">
                    @if (empty($mimeCounts))
                        <x-admin.empty-state title="Tidak ada data" />
                    @else
                        @php $totalMime = max(1, array_sum($mimeCounts)); @endphp
                        <ul class="admin-documents-distribution-list" role="list">
                            @foreach ($mimeCounts as $mime => $count)
                                @php
                                    $mimeMeta = $fileTypeMeta((object) ['mime_type' => $mime, 'original_name' => '']);
                                    $pct = (int) round(($count / $totalMime) * 100);
                                @endphp
                                <li>
                                    <div class="admin-documents-distribution-list__top">
                                        <span title="{{ $mime }}">{{ $mimeMeta['label'] }}</span>
                                        <strong>{{ number_format($count) }}</strong>
                                    </div>
                                    <div class="admin-documents-distribution-bar" aria-hidden="true">
                                        <span style="width: {{ $pct }}%"></span>
                                    </div>
                                    <div class="admin-documents-distribution-list__meta">
                                        <span>{{ $pct }}%</span>
                                        <span>{{ number_format($count) }} file</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="admin-documents-distribution-panel admin-section">
                <header class="admin-documents-distribution-panel__header">
                    <div>
                        <h3>Status Pipeline</h3>
                        <p>Uploaded, parsed, indexed, ready.</p>
                    </div>
                </header>

                <div class="admin-documents-distribution-panel__body">
                    @if (empty($statusCounts))
                        <x-admin.empty-state title="Tidak ada status" />
                    @else
                        @php $totalStatus = max(1, array_sum($statusCounts)); @endphp
                        <ul class="admin-documents-status-list" role="list">
                            @foreach ($statusCounts as $statusName => $count)
                                @php
                                    $pct = (int) round(($count / $totalStatus) * 100);
                                    $statusItem = $statusMeta($statusName);
                                @endphp
                                <li>
                                    <span class="admin-documents-status-dot admin-documents-status-dot--{{ $statusItem['tone'] }}" aria-hidden="true"></span>
                                    <div>
                                        <strong>{{ $statusItem['label'] }}</strong>
                                        <em>{{ $pct }}% dari dokumen</em>
                                    </div>
                                    <b>{{ number_format($count) }}</b>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>
    </div>

    @if ($selectedDocument)
        @php
            $selectedType = $fileTypeMeta($selectedDocument);
            $selectedStatus = $statusMeta($selectedDocument->status);
            $selectedPipeline = $pipelineMeta($selectedDocument);
        @endphp
        <div class="admin-documents-drawer" role="dialog" aria-modal="true" aria-labelledby="admin-document-detail-title">
            <button type="button" class="admin-documents-drawer__backdrop" wire:click="closeDetail" aria-label="Tutup detail dokumen"></button>

            <aside class="admin-documents-drawer__panel">
                <header class="admin-documents-drawer__header">
                    <div class="admin-documents-drawer__title-row">
                        <span class="admin-documents-file-icon admin-documents-file-icon--{{ $selectedType['key'] }}" aria-hidden="true">
                            {{ $selectedType['label'] }}
                        </span>
                        <div class="min-w-0">
                            <p class="admin-documents-drawer__eyebrow">Document Pipeline</p>
                            <h3 id="admin-document-detail-title" title="{{ $selectedDocument->original_name }}">
                                {{ \Illuminate\Support\Str::limit((string) $selectedDocument->original_name, 64, '...') }}
                            </h3>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDetail" class="admin-documents-drawer__close" aria-label="Tutup detail dokumen">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="admin-documents-drawer__body">
                    <div class="admin-documents-drawer__summary">
                        <div>
                            <span>Status</span>
                            <strong class="admin-documents-status-chip admin-documents-status-chip--{{ $selectedStatus['tone'] }}">
                                {{ $selectedStatus['label'] }}
                            </strong>
                        </div>
                        <div>
                            <span>Owner</span>
                            <strong>{{ $selectedDocument->user?->name ?? 'Sistem' }}</strong>
                            <em>{{ $selectedDocument->user?->email ?? '-' }}</em>
                        </div>
                        <div>
                            <span>Size</span>
                            <strong>{{ $selectedDocument->formatted_size }}</strong>
                            <em>{{ $selectedDocument->mime_type ?? 'unknown' }}</em>
                        </div>
                        <div>
                            <span>Uploaded</span>
                            <strong>{{ $selectedDocument->created_at?->diffForHumans() ?? '-' }}</strong>
                            <em>{{ $selectedDocument->created_at?->toDateTimeString() ?? '-' }}</em>
                        </div>
                    </div>

                    <section class="admin-documents-drawer__section">
                        <div class="admin-documents-drawer__section-heading">
                            <h4>Pipeline</h4>
                            <span>{{ $selectedPipeline['progress'] }}%</span>
                        </div>
                        <div class="admin-documents-pipeline admin-documents-pipeline--drawer">
                            <div class="admin-documents-pipeline__track" aria-hidden="true">
                                <span class="admin-documents-pipeline__bar admin-documents-pipeline__bar--{{ $selectedPipeline['tone'] }}" style="width: {{ $selectedPipeline['progress'] }}%"></span>
                            </div>
                        </div>
                        <ol class="admin-documents-stage-list" role="list">
                            @foreach ($selectedPipeline['stages'] as $stage)
                                <li class="admin-documents-stage-list__item admin-documents-stage-list__item--{{ $stage['state'] }}">
                                    <span aria-hidden="true"></span>
                                    <strong>{{ $stage['label'] }}</strong>
                                </li>
                            @endforeach
                        </ol>
                    </section>

                    <section class="admin-documents-drawer__section">
                        <h4>AI Index Metadata</h4>
                        <dl class="admin-documents-metadata-grid">
                            <div>
                                <dt>Parsing Status</dt>
                                <dd>{{ $selectedPipeline['parse_status'] }}</dd>
                            </div>
                            <div>
                                <dt>Chunk Count</dt>
                                <dd>{{ number_format($selectedPipeline['chunk_count']) }}</dd>
                            </div>
                            <div>
                                <dt>Embedding Status</dt>
                                <dd>{{ $selectedPipeline['embedding_status'] }}</dd>
                            </div>
                            <div>
                                <dt>Preview Status</dt>
                                <dd>{{ ucfirst((string) ($selectedDocument->preview_status ?? 'pending')) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="admin-documents-drawer__section">
                        <h4>File Metadata</h4>
                        <dl class="admin-documents-metadata-grid">
                            <div>
                                <dt>File Name</dt>
                                <dd>{{ $selectedDocument->filename ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Original Name</dt>
                                <dd>{{ $selectedDocument->original_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Source</dt>
                                <dd>{{ strtoupper(str_replace('_', ' ', (string) ($selectedDocument->source_provider ?? 'local'))) }}</dd>
                            </div>
                            <div>
                                <dt>External ID</dt>
                                <dd>{{ $selectedDocument->source_external_id ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Synced At</dt>
                                <dd>{{ $selectedDocument->source_synced_at?->toDateTimeString() ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Updated At</dt>
                                <dd>{{ $selectedDocument->updated_at?->toDateTimeString() ?? '-' }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </aside>
        </div>
    @endif
</div>
