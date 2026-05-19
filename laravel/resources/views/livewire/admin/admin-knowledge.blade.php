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
            'type_label' => match ($type) {
                'pdf' => 'APPLICATION/PDF',
                'csv' => 'TEXT/CSV',
                'xlsx' => 'XLSX',
                'docx' => 'DOCX',
                default => strtoupper($mime ?: ($extension ?: 'UNKNOWN')),
            },
        ];
    };
    $statusMeta = function (?string $status): array {
        return match ($status) {
            'active' => ['label' => 'Active', 'tone' => 'success'],
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

    $totalDocs = array_sum($statusCounts);
    $activeCount = (int) ($statusCounts['active'] ?? 0);
    $processingCount = (int) (($statusCounts['draft'] ?? 0) + ($statusCounts['processing'] ?? 0));
    $errorCount = (int) ($statusCounts['error'] ?? 0);
    $archivedCount = (int) ($statusCounts['archived'] ?? 0);

    $knowledgeCards = [
        [
            'label' => 'Total Knowledge',
            'value' => $totalDocs,
            'description' => $formatInt($archivedCount) . ' archived',
            'tone' => 'primary',
            'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253',
        ],
        [
            'label' => 'Active',
            'value' => $activeCount,
            'description' => $formatPct($activeCount, $totalDocs) . ' siap dipakai',
            'tone' => 'success',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Draft / Processing',
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
    <div class="admin-knowledge-hero">
        <div class="max-w-2xl">
            <p class="admin-knowledge-eyebrow">Knowledge</p>
            <h2 class="admin-knowledge-title">Knowledge Base Internal</h2>
            <p class="admin-knowledge-description">
                Kelola dokumen internal global untuk referensi AI tanpa menampilkan isi dokumen.
            </p>
        </div>
        <x-admin.badge tone="neutral" class="admin-knowledge-readonly">Admin only</x-admin.badge>
    </div>

    @if (session('knowledge_status'))
        <div class="admin-knowledge-alert">
            {{ session('knowledge_status') }}
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

    <div class="admin-knowledge-content-grid">
        <div class="admin-knowledge-main-stack">
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
                        <p>Maksimum 100 baris pada filter aktif.</p>
                    </div>
                </header>

                <div class="admin-knowledge-table-panel__body">
                    @if ($documents->isEmpty())
                        <x-admin.empty-state title="Belum ada knowledge" description="Belum ada dokumen knowledge yang cocok dengan filter saat ini." />
                    @else
                        <x-admin.table
                            class="admin-knowledge-table"
                            :columns="[
                                ['key' => 'file', 'label' => 'File', 'width' => '29%'],
                                ['key' => 'source', 'label' => 'Source', 'width' => '16%'],
                                ['key' => 'admin', 'label' => 'Admin', 'width' => '19%'],
                                ['key' => 'type', 'label' => 'Tipe', 'width' => '12%'],
                                ['key' => 'size', 'label' => 'Size', 'align' => 'right', 'width' => '9%'],
                                ['key' => 'status', 'label' => 'Status', 'width' => '11%'],
                                ['key' => 'time', 'label' => 'Dibuat', 'align' => 'right', 'width' => '10%'],
                                ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '14%'],
                            ]">
                            @foreach ($documents as $doc)
                                @php
                                    $typeMeta = $fileTypeMeta($doc);
                                    $status = $statusMeta($doc->status);
                                @endphp
                                <tr>
                                    <td class="admin-table__td">
                                        <div class="admin-documents-file-cell">
                                            <x-admin.document-icon :type="$typeMeta['key']" :label="$typeMeta['label']" />
                                            <div class="min-w-0">
                                                <span class="admin-knowledge-file-name" title="{{ $doc->title }}">{{ \Illuminate\Support\Str::limit((string) $doc->title, 52, '...') }}</span>
                                                <span class="admin-knowledge-file-meta">{{ \Illuminate\Support\Str::limit((string) $doc->original_name, 52, '...') }}</span>
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
                                        <span class="admin-documents-type-label">{{ $typeMeta['type_label'] }}</span>
                                    </td>
                                    <td class="admin-table__td" data-align="right">
                                        <span class="admin-documents-number">{{ $doc->formatted_size }}</span>
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
                                    <td class="admin-table__td" data-align="right">
                                        <span class="admin-documents-muted" title="{{ $doc->created_at?->toDateTimeString() }}">{{ $doc->created_at?->diffForHumans() }}</span>
                                    </td>
                                    <td class="admin-table__td" data-align="right">
                                        <div class="admin-knowledge-action-group">
                                            @if ($doc->status !== 'active')
                                                <button type="button" wire:click="activate({{ $doc->id }})" class="admin-knowledge-action admin-knowledge-action--success">Aktifkan</button>
                                            @endif
                                            @if ($doc->status !== 'archived')
                                                <button type="button" wire:click="archive({{ $doc->id }})" class="admin-knowledge-action admin-knowledge-action--warning">Arsip</button>
                                            @endif
                                            <button type="button" wire:click="reprocess({{ $doc->id }})" class="admin-knowledge-action">Proses ulang</button>
                                            <button type="button"
                                                    wire:click="delete({{ $doc->id }})"
                                                    wire:confirm="Yakin hapus knowledge ini? Vector akan dihapus juga."
                                                    class="admin-knowledge-action admin-knowledge-action--danger">
                                                Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </x-admin.table>
                    @endif
                </div>
            </section>
        </div>

        <aside class="admin-knowledge-side-grid">
            <section class="admin-knowledge-upload-panel admin-section">
                <header class="admin-knowledge-upload-panel__header">
                    <div>
                        <h3>Upload Knowledge</h3>
                        <p>Format: {{ implode(', ', $allowedExtensions) }}.</p>
                    </div>
                </header>

                <form wire:submit.prevent="upload" class="admin-knowledge-upload-form">
                    <label class="admin-knowledge-filter">
                        <span>Judul opsional</span>
                        <input type="text" wire:model.defer="title" placeholder="Contoh: SOP Penerimaan Tamu" class="admin-knowledge-control" />
                        @error('title') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>Source existing</span>
                        <select wire:model.defer="sourceId" class="admin-knowledge-control">
                            <option value="">Pilih source</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
                        @error('sourceId') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>Atau source baru</span>
                        <input type="text" wire:model.defer="newSourceName" placeholder="Contoh: Aturan internal" class="admin-knowledge-control" />
                        @error('newSourceName') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>Catatan internal</span>
                        <textarea wire:model.defer="notes" rows="2" placeholder="Konteks singkat untuk admin lain" class="admin-knowledge-control admin-knowledge-control--textarea"></textarea>
                        @error('notes') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="admin-knowledge-filter">
                        <span>File knowledge</span>
                        <input type="file" wire:model="file" accept=".pdf,.docx,.xlsx,.csv" class="admin-knowledge-control admin-knowledge-control--file" />
                        @error('file') <span class="admin-knowledge-error">{{ $message }}</span> @enderror
                    </label>

                    <div wire:loading wire:target="file" class="admin-knowledge-upload-note">Meng-upload file...</div>

                    <button type="submit"
                            class="admin-knowledge-upload-button"
                            wire:loading.attr="disabled"
                            wire:target="upload,file">
                        <span wire:loading.remove wire:target="upload">Upload Knowledge</span>
                        <span wire:loading wire:target="upload">Memproses...</span>
                    </button>
                </form>
            </section>

            <section class="admin-knowledge-status-panel admin-section">
                <header class="admin-knowledge-status-panel__header">
                    <div>
                        <h3>Status Knowledge</h3>
                        <p>Berdasarkan seluruh dokumen.</p>
                    </div>
                </header>

                <div class="admin-knowledge-status-panel__body">
                    @if (empty($statusCounts))
                        <x-admin.empty-state title="Tidak ada status" />
                    @else
                        @php $totalStatus = max(1, array_sum($statusCounts)); @endphp
                        <ul class="admin-documents-status-list" role="list">
                            @foreach ($statusCounts as $statusName => $count)
                                @php
                                    $statusItem = $statusMeta($statusName);
                                    $pct = (int) round(($count / $totalStatus) * 100);
                                @endphp
                                <li>
                                    <span class="admin-documents-status-dot admin-documents-status-dot--{{ $statusItem['tone'] }}" aria-hidden="true"></span>
                                    <div>
                                        <strong>{{ $statusItem['label'] }}</strong>
                                        <em>{{ $pct }}% dari knowledge</em>
                                    </div>
                                    <b>{{ number_format($count) }}</b>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</div>
