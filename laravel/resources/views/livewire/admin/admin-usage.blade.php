@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = function (int $value, int $total): string {
        if ($total <= 0) {
            return '0%';
        }

        return ((int) round(($value / $total) * 100)) . '%';
    };

    $totalEvents = (int) ($totals['total'] ?? 0);
    $successEvents = (int) ($totals['success'] ?? 0);
    $pendingEvents = (int) ($totals['pending'] ?? 0);
    $failedEvents = (int) ($totals['failed'] ?? 0);
    $statusDescription = function (int $value, string $label, string $empty) use ($formatPct, $totalEvents): string {
        if ($value <= 0) {
            return $empty;
        }

        return $formatPct($value, $totalEvents) . ' ' . $label;
    };

    $usageCards = [
        [
            'label' => 'Total Event',
            'value' => $totalEvents,
            'description' => $totalEvents > 0 ? 'Event terfilter' : 'Belum ada event',
            'tone' => 'primary',
            'icon' => 'M4 19V10m5 9V5m5 14v-7m5 7V8M3 19h18',
        ],
        [
            'label' => 'Sukses',
            'value' => $successEvents,
            'description' => $statusDescription($successEvents, 'sukses', 'Tidak ada sukses'),
            'tone' => $successEvents > 0 ? 'success' : 'neutral',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Pending',
            'value' => $pendingEvents,
            'description' => $statusDescription($pendingEvents, 'pending', 'Tidak ada pending'),
            'tone' => $pendingEvents > 0 ? 'warning' : 'neutral',
            'icon' => 'M12 6v6l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Gagal',
            'value' => $failedEvents,
            'description' => $statusDescription($failedEvents, 'gagal', 'Tidak ada gagal'),
            'tone' => $failedEvents > 0 ? 'danger' : 'neutral',
            'icon' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        ],
    ];

    $featureLabel = fn (?string $feature): string => $featureOptions[$feature] ?? strtoupper(str_replace('_', '.', (string) ($feature ?: '—')));
    $formatModelLabel = function ($event): array {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $subjectEmbeddingProvider = '';
        $subject = $event->relationLoaded('subject') ? $event->subject : null;

        if ($subject instanceof \App\Models\Document) {
            $subjectEmbeddingProvider = trim((string) $subject->embedding_provider);
        }

        $label = trim((string) ($metadata['model_label'] ?? $metadata['model_name'] ?? $metadata['embedding_provider'] ?? $subjectEmbeddingProvider));
        $name = trim((string) ($metadata['model_name'] ?? $metadata['embedding_provider'] ?? $subjectEmbeddingProvider));
        $provider = trim((string) ($metadata['model_provider'] ?? ($subjectEmbeddingProvider !== '' ? 'embedding' : '')));

        if ($label === '') {
            return [
                'label' => '-',
                'title' => 'Model belum tercatat untuk event ini.',
                'muted' => true,
            ];
        }

        $titleParts = array_filter([
            $name !== '' ? 'Model: ' . $name : null,
            $provider !== '' ? 'Provider: ' . $provider : null,
            $event->request_id ? 'Request ID: ' . $event->request_id : null,
        ]);

        return [
            'label' => \Illuminate\Support\Str::limit($label, 22),
            'title' => implode(' | ', $titleParts) ?: $label,
            'muted' => false,
        ];
    };
@endphp

<div class="admin-usage-page">
    <div class="admin-usage-hero">
        <div class="max-w-2xl">
            <p class="admin-usage-eyebrow">Monitoring</p>
            <h2 class="admin-usage-title">Usage Events</h2>
            <p class="admin-usage-description">
                Pantau metadata event AI per fitur, status, dan rentang tanggal.
            </p>
        </div>
        <x-admin.badge tone="neutral" class="admin-usage-readonly">Read-only</x-admin.badge>
    </div>

    <div class="admin-usage-kpi-grid">
        @foreach ($usageCards as $card)
            <article class="admin-usage-kpi-card admin-usage-kpi-card--{{ $card['tone'] }}">
                <div class="admin-usage-kpi-card__header">
                    <span class="admin-usage-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-usage-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-usage-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-usage-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <section class="admin-usage-filter-panel admin-section">
        <div class="admin-usage-filter-panel__header">
            <h3>Filter</h3>
            <div class="admin-usage-reset-group">
                <label class="admin-usage-lifecycle-toggle">
                    <input type="checkbox" wire:model.live="showLifecycleEvents">
                    <span>Tampilkan started</span>
                </label>
                <button type="button" wire:click="resetFilters" class="admin-usage-reset-button">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                    </svg>
                    Reset
                </button>
            </div>
        </div>

        <div class="admin-usage-filter-grid">
            <label class="admin-usage-filter">
                <span>Fitur</span>
                <select wire:model.live="feature" class="admin-usage-control">
                    <option value="">Semua</option>
                    @foreach ($featureOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-usage-filter">
                <span>Status</span>
                <select wire:model.live="status" class="admin-usage-control">
                    <option value="">Semua</option>
                    @foreach ($statusOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-usage-filter">
                <span>Tanggal Mulai</span>
                <input type="date" wire:model.live="startDate" class="admin-usage-control" />
            </label>

            <label class="admin-usage-filter">
                <span>Tanggal Akhir</span>
                <input type="date" wire:model.live="endDate" class="admin-usage-control" />
            </label>
        </div>
    </section>

    <div class="admin-usage-content-grid">
        <section class="admin-usage-table-panel admin-section">
            <header class="admin-usage-table-panel__header">
                <div>
                    <h3>Event Terbaru</h3>
                    <p>
                        Menampilkan {{ $eventsPerPage }} event per halaman{{ $hideLifecycleEvents ? ', event started disembunyikan.' : ' pada filter aktif.' }}
                    </p>
                </div>
            </header>

            <div class="admin-usage-table-panel__body">
                @if ($events->isEmpty())
                    <x-admin.empty-state
                        title="Belum ada event"
                        description="Tidak ada event AI pada rentang dan filter saat ini." />
                @else
                    <x-admin.table
                        class="admin-usage-table"
                        :columns="[
                            ['key' => 'user', 'label' => 'User', 'width' => '23%'],
                            ['key' => 'feature', 'label' => 'Fitur', 'width' => '13%'],
                            ['key' => 'action', 'label' => 'Action', 'width' => '12%'],
                            ['key' => 'model', 'label' => 'Model', 'width' => '17%'],
                            ['key' => 'status', 'label' => 'Status', 'width' => '11%'],
                            ['key' => 'latency', 'label' => 'Latensi', 'align' => 'right', 'width' => '10%'],
                            ['key' => 'time', 'label' => 'Waktu', 'align' => 'right', 'width' => '14%'],
                        ]">
                        @foreach ($events as $event)
                            @php
                                $statusTone = match ($event->status) {
                                    'success' => 'success',
                                    'error', 'blocked' => 'danger',
                                    'pending' => 'warning',
                                    default => 'neutral',
                                };
                                $model = $formatModelLabel($event);
                            @endphp
                            <tr>
                                <td class="admin-table__td">
                                    <div class="admin-usage-user-cell">
                                        <span class="admin-usage-user-cell__name">{{ $event->user?->name ?? 'Sistem' }}</span>
                                        <span class="admin-usage-user-cell__email">{{ $event->user?->email ?? '—' }}</span>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-usage-feature">{{ $featureLabel($event->feature) }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-usage-feature">{{ strtoupper(str_replace('_', '.', $event->action)) }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <span @class([
                                        'admin-usage-model',
                                        'admin-usage-model--muted' => $model['muted'],
                                    ]) title="{{ $model['title'] }}">
                                        {{ $model['label'] }}
                                    </span>
                                </td>
                                <td class="admin-table__td">
                                    <x-admin.badge :tone="$statusTone" class="admin-usage-status-badge">
                                        {{ ucfirst($event->status) }}
                                    </x-admin.badge>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="admin-usage-number">{{ $event->latency_ms !== null ? number_format($event->latency_ms) . ' ms' : '—' }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="admin-usage-muted" title="{{ $event->created_at?->toDateTimeString() }}">{{ $event->created_at?->diffForHumans() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>

                    @if ($events->hasPages())
                        <div class="admin-usage-pagination">
                            {{ $events->links('admin.pagination') }}
                        </div>
                    @endif
                @endif
            </div>
        </section>

        <section class="admin-usage-distribution-panel admin-section">
            <header class="admin-usage-distribution-panel__header">
                <div>
                    <h3>Distribusi Fitur</h3>
                    <p>Berdasarkan rentang yang dipilih.</p>
                </div>
            </header>

            <div class="admin-usage-distribution-panel__body">
                @if (empty($distribution))
                    <x-admin.empty-state title="Belum ada data" description="Tidak ada event pada rentang ini." />
                @else
                    @php $totalDist = max(1, collect($distribution)->sum('total')); @endphp
                    <ul class="admin-usage-distribution-list" role="list">
                        @foreach ($distribution as $row)
                            @php $pct = (int) round(($row['total'] / $totalDist) * 100); @endphp
                            <li>
                                <div class="admin-usage-distribution-list__top">
                                    <span>{{ $featureLabel($row['feature']) }}</span>
                                    <strong>{{ number_format($row['total']) }}</strong>
                                </div>
                                <div class="admin-usage-distribution-bar" aria-hidden="true">
                                    <span style="width: {{ $pct }}%"></span>
                                </div>
                                <div class="admin-usage-distribution-list__meta">
                                    <span>{{ $pct }}%</span>
                                    <span>Sukses {{ number_format($row['success']) }}</span>
                                    <span>Gagal {{ number_format($row['failed']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>
</div>
