@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = fn ($value): string => number_format((float) $value, 0, ',', '.') . '%';
    $formatSeconds = fn ($milliseconds): string => number_format(((float) $milliseconds) / 1000, 2, ',', '.') . 's';
    $trendClass = function (?array $trend): string {
        return match ($trend['tone'] ?? 'neutral') {
            'success' => 'text-emerald-600 dark:text-emerald-400',
            'danger' => 'text-rose-600 dark:text-rose-400',
            default => 'text-stone-500 dark:text-gray-400',
        };
    };
    $trendLabel = function (?array $trend, string $basis) use ($formatPct, $formatSeconds): ?string {
        if (empty($trend) || ! ($trend['has_comparison'] ?? false)) {
            return null;
        }

        if (($trend['direction'] ?? 'none') === 'flat') {
            return 'Tidak berubah dari ' . $basis;
        }

        $icon = ($trend['direction'] ?? 'none') === 'up' ? 'Naik' : 'Turun';
        $delta = abs((float) ($trend['delta'] ?? 0));
        $unit = $trend['unit'] ?? 'percent';

        $value = match ($unit) {
            'percentage_points' => number_format($delta, 0, ',', '.') . ' poin',
            'milliseconds' => $formatSeconds($delta),
            default => $formatPct(abs((float) ($trend['delta_percent'] ?? 0))),
        };

        return $icon . ' ' . $value . ' dari ' . $basis;
    };

    $requestsToday = (int) ($kpis['ai_requests_today'] ?? 0);
    $successToday = (int) ($kpis['ai_success_today'] ?? 0);
    $failedToday = (int) ($kpis['ai_failed_today'] ?? 0);
    $successRate = $requestsToday > 0 ? ($successToday / $requestsToday) * 100 : 0;
    $errorRate = $requestsToday > 0 ? ($failedToday / $requestsToday) * 100 : 0;
    $onlineUsers = (int) ($kpis['online_users'] ?? 0);
    $documentsReady = (int) ($kpis['documents_ready'] ?? 0);
    $documentsProcessing = (int) ($kpis['documents_processing'] ?? 0);
    $documentsFailed = (int) ($kpis['documents_failed'] ?? 0);
    $documentsTotal = $documentsReady + $documentsProcessing + $documentsFailed;
    $memoToday = (int) ($kpis['memos_today'] ?? 0);
    $totalErrorsRange = (int) collect($series)->sum('failed');
    $seriesTotal = (int) collect($series)->sum('total');
    $chartMax = max(1, (int) ($maxSeriesValue ?? 1));
    $seriesCount = count($series);
    $chartPoints = [];

    foreach ($series as $index => $point) {
        $x = $seriesCount > 1 ? 42 + (($index / ($seriesCount - 1)) * 556) : 320;
        $y = 180 - (((int) $point['total'] / $chartMax) * 126);
        $chartPoints[] = [
            'x' => round($x, 1),
            'y' => round($y, 1),
            'total' => (int) $point['total'],
            'date' => $point['date'],
            'label' => \Illuminate\Support\Carbon::parse($point['date'])->locale('id')->translatedFormat('j M'),
        ];
    }

    $linePath = collect($chartPoints)
        ->map(fn ($point, $index) => ($index === 0 ? 'M ' : 'L ') . $point['x'] . ' ' . $point['y'])
        ->implode(' ');
    $firstPoint = $chartPoints[0] ?? null;
    $lastPoint = $chartPoints[count($chartPoints) - 1] ?? null;
    $areaPath = $firstPoint && $lastPoint
        ? $linePath . ' L ' . $lastPoint['x'] . ' 180 L ' . $firstPoint['x'] . ' 180 Z'
        : '';
    $avgSeries = $seriesCount > 0 ? round($seriesTotal / $seriesCount) : 0;

    $requestTrend = $comparisons['ai_requests'] ?? null;
    $successRateTrend = $comparisons['success_rate'] ?? null;
    $errorRateTrend = $comparisons['error_rate'] ?? null;
    $errorsSevenDayTrend = $comparisons['errors_7d'] ?? null;
    $errorRateSevenDayTrend = $comparisons['error_rate_7d'] ?? null;
    $totalErrorsRange = (int) ($errorsSevenDayTrend['current'] ?? $totalErrorsRange);
    $errorRateSevenDay = (float) ($errorRateSevenDayTrend['current'] ?? $errorRate);

    $requestTrendText = $trendLabel($requestTrend, 'kemarin');
    $successRateTrendText = $trendLabel($successRateTrend, 'kemarin');
    $errorRateTrendText = $trendLabel($errorRateTrend, 'kemarin');
    $errorsSevenDayTrendText = $trendLabel($errorsSevenDayTrend, '7 hari lalu');
    $errorRateSevenDayTrendText = $trendLabel($errorRateSevenDayTrend, '7 hari lalu');
    $onlineUsersLabel = $onlineUsers > 0 ? $formatInt($onlineUsers) . ' online' : 'Tidak ada online';
    $aiUsageLabel = $requestsToday > 0 ? $formatPct($successRate) . ' sukses' : 'Belum ada request';
    $documentsLabel = match (true) {
        $documentsFailed > 0 => $formatInt($documentsFailed) . ' gagal',
        $documentsProcessing > 0 => $formatInt($documentsProcessing) . ' diproses',
        $documentsTotal > 0 && $documentsReady === $documentsTotal => 'Semua ready',
        $documentsReady > 0 => $formatInt($documentsReady) . ' ready',
        default => 'Belum ada dokumen',
    };
    $conversationLabel = $memoToday > 0 ? $formatInt($memoToday) . ' memo hari ini' : 'Tidak ada memo';

    $overviewCards = [
        [
            'title' => 'Users',
            'value' => $formatInt($kpis['total_users'] ?? 0),
            'label' => 'Total user',
            'meta' => $onlineUsersLabel,
            'meta_tone' => $onlineUsers > 0 ? 'success' : 'neutral',
            'support' => null,
            'tone' => 'primary',
            'route' => route('admin.users'),
            'icon' => 'M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 0115 0',
        ],
        [
            'title' => 'AI Usage',
            'value' => $formatInt($requestsToday),
            'label' => 'Request hari ini',
            'meta' => $aiUsageLabel,
            'meta_tone' => $requestsToday > 0 ? 'success' : 'neutral',
            'support' => null,
            'tone' => 'gold',
            'route' => route('admin.usage'),
            'icon' => 'M12 3l1.65 4.7L18 9.5l-4.35 1.8L12 16l-1.65-4.7L6 9.5l4.35-1.8L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z',
        ],
        [
            'title' => 'Documents',
            'value' => $formatInt($documentsReady),
            'label' => 'Dokumen ready',
            'meta' => $documentsLabel,
            'meta_tone' => $documentsFailed > 0 ? 'danger' : ($documentsProcessing > 0 ? 'warning' : ($documentsReady > 0 ? 'success' : 'neutral')),
            'support' => null,
            'tone' => $documentsFailed > 0 ? 'danger' : ($documentsProcessing > 0 ? 'warning' : 'success'),
            'route' => route('admin.documents'),
            'icon' => 'M8 3.75h6.25L19 8.5v11.75H8A3 3 0 015 17.25V6.75a3 3 0 013-3zM14 3.75V8.5H19M9 13h6M9 16h5',
        ],
        [
            'title' => 'Percakapan & Memo',
            'value' => $formatInt($kpis['conversations_today'] ?? 0),
            'label' => 'Percakapan baru',
            'meta' => $conversationLabel,
            'meta_tone' => 'neutral',
            'support' => null,
            'tone' => 'primary',
            'route' => route('admin.usage'),
            'icon' => 'M4.5 6.75A3.75 3.75 0 018.25 3h7.5a3.75 3.75 0 013.75 3.75v5A3.75 3.75 0 0115.75 15H11l-4.5 4.5V15A3.75 3.75 0 014.5 11.25v-4.5z',
        ],
    ];
@endphp

<div class="admin-overview">
    <div wire:loading.flex wire:target="setRange,refreshMetrics" class="mb-4 hidden">
        <x-admin.loading :rows="2" label="Memuat metrik dashboard" class="w-full" />
    </div>

    <label class="admin-filter sr-only" aria-hidden="true">
        <span class="admin-filter__label">Range</span>
        <select class="admin-filter__control" disabled>
            <option>{{ $rangeDays }}h</option>
        </select>
    </label>

    <section class="admin-overview-hero admin-section">
        <div class="relative z-10 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="admin-health-pulse" aria-hidden="true">
                    <span></span>
                </span>
                <span class="text-xs font-bold uppercase text-amber-600 dark:text-amber-300">Sistem sehat</span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <h2 class="admin-overview-title">Ringkasan Operasional</h2>
                <x-admin.badge tone="success">Live</x-admin.badge>
            </div>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-stone-600 dark:text-gray-400">
                Platform berjalan normal. Detail analitik tersedia di tab khusus.
            </p>
            <button type="button"
                    wire:click="refreshMetrics"
                    wire:loading.attr="disabled"
                    class="mt-4 inline-flex items-center gap-2 text-xs font-medium text-stone-500 transition hover:text-ista-primary disabled:opacity-60 dark:text-gray-400 dark:hover:text-amber-300">
                Terakhir diperbarui:
                {{ $lastUpdatedAt ? $lastUpdatedAt->diffForHumans() : 'Belum ada aktivitas' }}
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h5M20 20v-5h-5M4.5 9A8 8 0 0119 12M19.5 15A8 8 0 015 12"/>
                </svg>
            </button>
        </div>

        <div class="admin-hero-stats relative z-10">
            <div class="admin-hero-stat">
                <span>AI Request Hari Ini</span>
                <strong>{{ $formatInt($requestsToday) }}</strong>
                <em class="{{ $requestTrendText ? $trendClass($requestTrend) : 'text-stone-500 dark:text-gray-500' }}">
                    {{ $requestTrendText ?? 'Hari ini' }}
                </em>
            </div>
            <div class="admin-hero-stat">
                <span>Success Rate</span>
                <strong>{{ $formatPct($successRate) }}</strong>
                <em class="{{ $successRateTrendText ? $trendClass($successRateTrend) : 'text-stone-500 dark:text-gray-500' }}">
                    {{ $successRateTrendText ?? $formatInt($successToday) . ' sukses' }}
                </em>
            </div>
            <div class="admin-hero-stat">
                <span>Error Rate</span>
                <strong>{{ $formatPct($errorRate) }}</strong>
                <em class="{{ $errorRateTrendText ? $trendClass($errorRateTrend) : 'text-stone-500 dark:text-gray-500' }}">
                    {{ $errorRateTrendText ?? $formatInt($failedToday) . ' gagal' }}
                </em>
            </div>
        </div>
    </section>

    <div class="admin-summary-grid">
        @foreach ($overviewCards as $card)
            <a href="{{ $card['route'] }}" class="admin-summary-card admin-summary-card--{{ $card['tone'] }} admin-kpi admin-section">
                <div class="admin-summary-card__top">
                    <span class="admin-summary-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <h3>{{ $card['title'] }}</h3>
                </div>
                <div class="admin-summary-card__body">
                    <p>{{ $card['label'] }}</p>
                    <strong>{{ $card['value'] }}</strong>
                    <div>
                        <em class="admin-summary-card__meta--{{ $card['meta_tone'] ?? 'neutral' }}">{{ $card['meta'] }}</em>
                        @if (! empty($card['support']))
                            <em>{{ $card['support'] }}</em>
                        @endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    <div class="admin-overview-main-grid">
        <section class="admin-section admin-panel">
            <header class="admin-panel-header">
                <h3>Tren Aktivitas AI ({{ $rangeDays }} Hari Terakhir)</h3>
                <div class="admin-range-select">
                    <select wire:change="setRange($event.target.value)" aria-label="Rentang aktivitas AI">
                        @foreach ([7, 14, 30] as $option)
                            <option value="{{ $option }}" @selected($rangeDays === $option)>{{ $option }} hari</option>
                        @endforeach
                    </select>
                </div>
            </header>

            @if ($seriesTotal === 0)
                <div class="p-5">
                    <x-admin.empty-state
                        title="Belum ada aktivitas"
                        description="Event akan muncul setelah user memakai fitur AI." />
                </div>
            @else
                <div class="admin-line-chart" role="img" aria-label="Grafik aktivitas event AI per hari">
                    <svg viewBox="0 0 640 236" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="adminOverviewArea" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#a4063a" stop-opacity="0.16" />
                                <stop offset="100%" stop-color="#a4063a" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        @foreach ([54, 96, 138, 180] as $gridY)
                            <line x1="42" y1="{{ $gridY }}" x2="598" y2="{{ $gridY }}" class="admin-chart-grid" />
                        @endforeach
                        @foreach ([0, (int) round($chartMax / 2), $chartMax] as $index => $axisValue)
                            <text x="0" y="{{ [184, 120, 58][$index] }}" class="admin-chart-axis">{{ $formatInt($axisValue) }}</text>
                        @endforeach
                        <path d="{{ $areaPath }}" fill="url(#adminOverviewArea)" />
                        <path d="{{ $linePath }}" class="admin-chart-line" />
                        @foreach ($chartPoints as $point)
                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4.2" class="admin-chart-dot" />
                            <text x="{{ $point['x'] }}" y="{{ max(18, $point['y'] - 13) }}" text-anchor="middle" class="admin-chart-value">{{ $formatInt($point['total']) }}</text>
                            <text x="{{ $point['x'] }}" y="215" text-anchor="middle" class="admin-chart-label">{{ $point['label'] }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="admin-chart-legend">
                    <span><i class="bg-ista-primary"></i> Request</span>
                    <span><i class="border border-stone-400 bg-transparent"></i> Rata-rata ({{ $rangeDays }} Hari): {{ $formatInt($avgSeries) }}</span>
                </div>
            @endif
        </section>

        <section class="admin-section admin-panel">
            <header class="admin-panel-header">
                <h3>Insiden Terbaru</h3>
                <a href="{{ route('admin.errors') }}">Lihat semua</a>
            </header>
            <div class="admin-incident-summary admin-incident-summary--compact">
                <div>
                    <span>Total Error</span>
                    <strong>{{ $formatInt($totalErrorsRange) }}</strong>
                    <em class="{{ $errorsSevenDayTrendText ? $trendClass($errorsSevenDayTrend) : 'text-stone-500 dark:text-gray-500' }}">
                        {{ $errorsSevenDayTrendText ?? '7 hari terakhir' }}
                    </em>
                </div>
                <div>
                    <span>Error Rate</span>
                    <strong>{{ $formatPct($errorRateSevenDay) }}</strong>
                    <em class="{{ $errorRateSevenDayTrendText ? $trendClass($errorRateSevenDayTrend) : 'text-stone-500 dark:text-gray-500' }}">
                        {{ $errorRateSevenDayTrendText ?? 'Dari seluruh request' }}
                    </em>
                </div>
            </div>

            @if ($recentErrors->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state title="Tidak ada error" description="Sistem berjalan tanpa kegagalan." />
                </div>
            @else
                <ul class="admin-error-list admin-error-list--compact">
                    @foreach ($recentErrors as $error)
                        <li>
                            <span class="admin-error-icon" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4m0 4h.01M10.3 4.7L2.8 18a2 2 0 001.74 3h14.92a2 2 0 001.74-3L13.7 4.7a2 2 0 00-3.4 0z" />
                                </svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p title="{{ $error->feature }}">{{ strtoupper(str_replace('_', '.', (string) $error->feature)) }}</p>
                                <em>{{ $error->created_at?->format('d M Y, H:i') }} WIB</em>
                                @if ($error->error_code)
                                    <span class="admin-error-code">{{ $error->error_code }}</span>
                                @endif
                            </div>
                            <x-admin.badge tone="danger">Gagal</x-admin.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="admin-section admin-panel admin-activity-panel">
        <header class="admin-panel-header">
            <div>
                <h3>Aktivitas Terbaru</h3>
                <p>3 event terakhir. Detail lengkap ada di tab Usage.</p>
            </div>
            <a href="{{ route('admin.usage') }}">Lihat semua</a>
        </header>
        <x-admin.table :columns="[
            ['key' => 'user', 'label' => 'User'],
            ['key' => 'feature', 'label' => 'Fitur'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'latency', 'label' => 'Latensi', 'align' => 'right'],
            ['key' => 'time', 'label' => 'Waktu', 'align' => 'right'],
        ]" class="admin-overview-table">
            @forelse ($recentEvents as $event)
                @php
                    $statusTone = match ($event->status) {
                        'success' => 'success',
                        'error' => 'danger',
                        'pending' => 'warning',
                        'blocked' => 'danger',
                        default => 'neutral',
                    };
                    $statusLabel = match ($event->status) {
                        'success' => 'Sukses',
                        'error' => 'Gagal',
                        'pending' => 'Pending',
                        'blocked' => 'Blocked',
                        default => ucfirst((string) $event->status),
                    };
                    $displayFeature = strtoupper(str_replace('_', '.', (string) $event->feature));
                    $userName = $event->user?->name ?? 'Sistem';
                    $initial = strtoupper(substr($userName, 0, 1));
                @endphp
                <tr>
                    <td class="admin-table__td">
                        <div class="flex items-center gap-3">
                            <span class="admin-row-avatar">{{ $initial }}</span>
                            <div class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-stone-800 dark:text-gray-100">{{ $userName }}</span>
                                <span class="block truncate text-xs text-stone-500 dark:text-gray-500">{{ $event->user?->email ?? '-' }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="admin-table__td">
                        <span class="font-mono text-xs font-medium uppercase text-stone-600 dark:text-gray-400" title="{{ $event->feature }}">{{ $displayFeature }}</span>
                    </td>
                    <td class="admin-table__td">
                        <x-admin.badge :tone="$statusTone">{{ $statusLabel }}</x-admin.badge>
                    </td>
                    <td class="admin-table__td" data-align="right">
                        <span class="font-mono text-xs text-stone-600 dark:text-gray-400">
                            {{ $event->latency_ms !== null ? number_format(((float) $event->latency_ms) / 1000, 2, ',', '.') . 's' : '-' }}
                        </span>
                    </td>
                    <td class="admin-table__td" data-align="right">
                        <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $event->created_at?->toDateTimeString() }}">{{ $event->created_at?->diffForHumans() }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="admin-table__empty">
                        <x-admin.empty-state
                            title="Belum ada aktivitas"
                            description="Event akan muncul setelah user mengirim chat atau upload dokumen." />
                    </td>
                </tr>
            @endforelse
        </x-admin.table>
    </section>
</div>
