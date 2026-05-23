@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = function (int $value, int $total): string {
        if ($total <= 0) {
            return '0%';
        }

        return ((int) round(($value / $total) * 100)) . '%';
    };

    $totalErrors = (int) ($errorSummary['total'] ?? 0);
    $failedErrors = (int) ($errorSummary['error'] ?? 0);
    $blockedErrors = (int) ($errorSummary['blocked'] ?? 0);
    $uniqueCodes = (int) ($errorSummary['unique_codes'] ?? 0);
    $statusDescription = function (int $value, string $label, string $empty) use ($formatPct, $totalErrors): string {
        if ($value <= 0) {
            return $empty;
        }

        return $formatPct($value, $totalErrors) . ' ' . $label;
    };

    $errorCards = [
        [
            'label' => 'Total Issue',
            'value' => $totalErrors,
            'description' => $totalErrors > 0 ? 'Issue terfilter' : 'Tidak ada issue',
            'tone' => 'primary',
            'icon' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        ],
        [
            'label' => 'Error',
            'value' => $failedErrors,
            'description' => $statusDescription($failedErrors, 'error', 'Tidak ada error'),
            'tone' => $failedErrors > 0 ? 'danger' : 'neutral',
            'icon' => 'M6 18L18 6M6 6l12 12',
        ],
        [
            'label' => 'Blocked',
            'value' => $blockedErrors,
            'description' => $statusDescription($blockedErrors, 'blocked', 'Tidak ada blocked'),
            'tone' => $blockedErrors > 0 ? 'warning' : 'neutral',
            'icon' => 'M12 3l7.5 4.5v5.25c0 4.5-3.075 7.55-7.5 8.25-4.425-.7-7.5-3.75-7.5-8.25V7.5L12 3zm-3.5 9h7',
        ],
        [
            'label' => 'Kode Unik',
            'value' => $uniqueCodes,
            'description' => $uniqueCodes > 0 ? 'Kode tersanitasi' : 'Tidak ada kode',
            'tone' => 'neutral',
            'icon' => 'M7 8h10M7 12h10M7 16h7M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z',
        ],
    ];

    $featureLabel = fn (?string $feature): string => $featureOptions[$feature] ?? strtoupper(str_replace('_', '.', (string) ($feature ?: '-')));
    $codeLabel = fn (?string $code): string => strtoupper(str_replace('_', ' ', (string) ($code ?: 'unknown_error')));
    $modelLabel = function ($event): string {
        $metadata = is_array($event?->metadata) ? $event->metadata : [];

        return (string) ($metadata['model_label'] ?? $metadata['model_name'] ?? '-');
    };
    $metadataValue = function ($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        if ($value === null || $value === '') {
            return '-';
        }

        return (string) $value;
    };
@endphp

<div class="admin-errors-page">
    <div class="admin-errors-hero">
        <div class="max-w-2xl">
            <p class="admin-errors-eyebrow">Monitoring</p>
            <h2 class="admin-errors-title">Error Operasional</h2>
            <p class="admin-errors-description">
                Pantau event gagal dan blocked tanpa membuka isi percakapan, dokumen, atau memo.
            </p>
        </div>
        <x-admin.badge tone="neutral" class="admin-errors-readonly">Read-only</x-admin.badge>
    </div>

    <div class="admin-errors-kpi-grid">
        @foreach ($errorCards as $card)
            <article class="admin-errors-kpi-card admin-errors-kpi-card--{{ $card['tone'] }}">
                <div class="admin-errors-kpi-card__header">
                    <span class="admin-errors-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-errors-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-errors-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-errors-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <section class="admin-errors-filter-panel admin-section">
        <div class="admin-errors-filter-panel__header">
            <h3>Filter</h3>
            <div class="admin-errors-reset-group">
                <button type="button" wire:click="resetFilters" class="admin-errors-reset-button">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                    </svg>
                    Reset
                </button>
            </div>
        </div>

        <div class="admin-errors-filter-grid">
            <label class="admin-errors-filter">
                <span>Fitur</span>
                <select wire:model.live="feature" class="admin-errors-control">
                    <option value="">Semua</option>
                    @foreach ($featureOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-errors-filter">
                <span>Trace</span>
                <input type="search"
                       wire:model.live.debounce.400ms="requestId"
                       placeholder="Cari request ID"
                       class="admin-errors-control" />
            </label>

            <label class="admin-errors-filter">
                <span>Tanggal Mulai</span>
                <input type="date" wire:model.live="startDate" class="admin-errors-control" />
            </label>

            <label class="admin-errors-filter">
                <span>Tanggal Akhir</span>
                <input type="date" wire:model.live="endDate" class="admin-errors-control" />
            </label>
        </div>
    </section>

    <div class="admin-errors-content-grid">
        <section class="admin-errors-table-panel admin-section">
            <header class="admin-errors-table-panel__header">
                <div>
                    <h3>Error Terbaru</h3>
                    <p>Menampilkan {{ $errorsPerPage }} error per halaman pada filter aktif.</p>
                </div>
                @if ($errorSummary['latest_at'] ?? null)
                    <span class="admin-errors-latest" title="{{ $errorSummary['latest_at']->toDateTimeString() }}">
                        Terbaru {{ $errorSummary['latest_at']->diffForHumans() }}
                    </span>
                @endif
            </header>

            <div class="admin-errors-table-panel__body">
                @if ($errors->isEmpty())
                    <x-admin.empty-state
                        title="Tidak ada error"
                        description="Tidak ada event gagal atau blocked pada filter saat ini." />
                @else
                    <x-admin.table
                        class="admin-errors-table"
                        :columns="[
                            ['key' => 'user', 'label' => 'User', 'width' => '24%'],
                            ['key' => 'feature', 'label' => 'Fitur', 'width' => '14%'],
                            ['key' => 'code', 'label' => 'Kode Error', 'width' => '22%'],
                            ['key' => 'severity', 'label' => 'Severity', 'width' => '12%'],
                            ['key' => 'time', 'label' => 'Waktu', 'align' => 'right', 'width' => '14%'],
                            ['key' => 'action', 'label' => 'Aksi', 'align' => 'right', 'width' => '14%'],
                        ]">
                        @foreach ($errors as $error)
                            <tr>
                                <td class="admin-table__td">
                                    <div class="admin-errors-user-cell">
                                        <span class="admin-errors-user-cell__name">{{ $error->user?->name ?? 'Sistem' }}</span>
                                        <span class="admin-errors-user-cell__email">{{ $error->user?->email ?? '-' }}</span>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-errors-feature">{{ $featureLabel($error->feature) }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-errors-code" title="{{ $error->error_code ?? 'unknown_error' }}">
                                        {{ $codeLabel($error->error_code) }}
                                    </span>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-errors-severity admin-errors-severity--{{ $error->getAttribute('severity_level') }}" title="{{ $error->getAttribute('severity_description') }}">
                                        {{ $error->getAttribute('severity_label') }}
                                    </span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="admin-errors-muted" title="{{ $error->created_at?->toDateTimeString() }}">{{ $error->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <button type="button" wire:click="showDetail({{ $error->id }})" class="admin-errors-detail-button">
                                        Detail
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>

                    @if ($errors->hasPages())
                        <div class="admin-errors-pagination" wire:key="admin-errors-pagination-{{ $errors->currentPage() }}-{{ $errors->lastPage() }}-{{ $errors->total() }}-{{ $errors->firstItem() }}-{{ $errors->lastItem() }}">
                            {{ $errors->links('admin.pagination') }}
                        </div>
                    @endif
                @endif
            </div>
        </section>

        <div class="admin-errors-side-grid">
            <section class="admin-errors-distribution-panel admin-section">
                <header class="admin-errors-distribution-panel__header">
                    <div>
                        <h3>Berdasarkan Fitur</h3>
                        <p>Distribusi issue pada filter aktif.</p>
                    </div>
                </header>

                <div class="admin-errors-distribution-panel__body">
                    @if (empty($byFeature))
                        <x-admin.empty-state title="Tidak ada error" />
                    @else
                        @php $totalFeature = max(1, collect($byFeature)->sum('total')); @endphp
                        <ul class="admin-errors-distribution-list" role="list">
                            @foreach ($byFeature as $row)
                                @php $pct = (int) round(($row['total'] / $totalFeature) * 100); @endphp
                                <li>
                                    <div class="admin-errors-distribution-list__top">
                                        <span>{{ $featureLabel($row['feature']) }}</span>
                                        <strong>{{ number_format($row['total']) }}</strong>
                                    </div>
                                    <div class="admin-errors-distribution-bar" aria-hidden="true">
                                        <span style="width: {{ $pct }}%"></span>
                                    </div>
                                    <div class="admin-errors-distribution-list__meta">
                                        <span>{{ $pct }}%</span>
                                        <span>{{ number_format($row['total']) }} issue</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="admin-errors-distribution-panel admin-section">
                <header class="admin-errors-distribution-panel__header">
                    <div>
                        <h3>Kode Error</h3>
                        <p>Error code yang paling sering muncul.</p>
                    </div>
                </header>

                <div class="admin-errors-distribution-panel__body">
                    @if (empty($byCode))
                        <x-admin.empty-state title="Tidak ada kode error" description="Belum ada error code pada filter ini." />
                    @else
                        @php $totalCode = max(1, collect($byCode)->sum('total')); @endphp
                        <ul class="admin-errors-code-list" role="list">
                            @foreach ($byCode as $row)
                                @php $pct = (int) round(($row['total'] / $totalCode) * 100); @endphp
                                <li>
                                    <div>
                                        <span>{{ $codeLabel($row['code']) }}</span>
                                        <em>{{ $pct }}% dari issue</em>
                                    </div>
                                    <strong>{{ number_format($row['total']) }}</strong>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>
    </div>

    @if ($selectedError)
        <div class="admin-errors-modal" role="dialog" aria-modal="true" aria-labelledby="admin-error-detail-title">
            <button type="button" class="admin-errors-modal__backdrop" wire:click="closeDetail" aria-label="Tutup detail error"></button>

            <section class="admin-errors-modal__panel">
                <header class="admin-errors-modal__header">
                    <div>
                        <p class="admin-errors-modal__eyebrow">Incident Detail</p>
                        <h3 id="admin-error-detail-title">Detail Error</h3>
                        <span class="admin-errors-modal__code">{{ $codeLabel($selectedError->error_code) }}</span>
                    </div>
                    <button type="button" wire:click="closeDetail" class="admin-errors-modal__close" aria-label="Tutup detail error">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="admin-errors-modal__body">
                    <div class="admin-errors-modal__summary-grid">
                        <div>
                            <span>Severity</span>
                            <strong class="admin-errors-severity admin-errors-severity--{{ $selectedError->getAttribute('severity_level') }}">
                                {{ $selectedError->getAttribute('severity_label') }}
                            </strong>
                            <em>{{ $selectedError->getAttribute('severity_description') }}</em>
                        </div>
                        <div>
                            <span>Trace</span>
                            <strong class="admin-errors-modal__trace">{{ $selectedError->request_id ?? '-' }}</strong>
                            <em>{{ $selectedError->created_at?->toDateTimeString() ?? '-' }}</em>
                        </div>
                        <div>
                            <span>Fitur</span>
                            <strong>{{ $featureLabel($selectedError->feature) }}</strong>
                            <em>{{ ucfirst($selectedError->status) }} · {{ $selectedError->latency_ms !== null ? number_format($selectedError->latency_ms) . ' ms' : 'latensi belum ada' }}</em>
                        </div>
                        <div>
                            <span>User</span>
                            <strong>{{ $selectedError->user?->name ?? 'Sistem' }}</strong>
                            <em>{{ $selectedError->user?->email ?? '-' }}</em>
                        </div>
                        <div>
                            <span>Model</span>
                            <strong>{{ $modelLabel($selectedError) }}</strong>
                            <em>{{ data_get($selectedError->metadata, 'model_provider', '-') }}</em>
                        </div>
                    </div>

                    <section class="admin-errors-modal__section">
                        <h4>Ringkasan</h4>
                        <p>{{ $selectedError->getAttribute('handling_summary') }}</p>
                    </section>

                    <section class="admin-errors-modal__section">
                        <h4>Kemungkinan Penyebab</h4>
                        <ul>
                            @foreach ((array) $selectedError->getAttribute('handling_causes') as $cause)
                                <li>{{ $cause }}</li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="admin-errors-modal__section">
                        <h4>Langkah Penanganan</h4>
                        <ol>
                            @foreach ((array) $selectedError->getAttribute('handling_steps') as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                    </section>

                    <section class="admin-errors-modal__section">
                        <h4>Metadata Aman</h4>
                        @if (empty($selectedError->metadata))
                            <p>Tidak ada metadata tambahan.</p>
                        @else
                            <dl class="admin-errors-modal__metadata">
                                @foreach ($selectedError->metadata as $key => $value)
                                    <div>
                                        <dt>{{ strtoupper(str_replace('_', ' ', (string) $key)) }}</dt>
                                        <dd>{{ $metadataValue($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </section>
                </div>
            </section>
        </div>
    @endif
</div>
