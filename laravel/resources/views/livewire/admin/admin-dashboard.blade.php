<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Selamat datang</p>
                <h2 class="admin-page-title mt-1">Ringkasan Operasional</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Pantau aktivitas user, performa AI, dan kesehatan platform dalam satu halaman. Data diambil dari event tracking ISTA AI dan metadata operasional, tanpa menampilkan isi prompt atau jawaban.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.badge tone="primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-ista-primary"></span>
                    Live
                </x-admin.badge>
                <x-admin.badge tone="neutral">Read-only</x-admin.badge>
                <button type="button"
                        wire:click="refreshMetrics"
                        wire:loading.attr="disabled"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary disabled:opacity-60 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-ista-primary/40 dark:hover:text-amber-300">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h5M20 20v-5h-5M4.5 9A8 8 0 0119 12M19.5 15A8 8 0 015 12"/>
                    </svg>
                    Refresh
                </button>
            </div>
        </div>
    </div>

    <div wire:loading.flex wire:target="setRange,refreshMetrics" class="mb-4 hidden">
        <x-admin.loading :rows="2" label="Memuat metrik dashboard" class="w-full" />
    </div>

    <label class="admin-filter sr-only" aria-hidden="true">
        <span class="admin-filter__label">Range</span>
        <select class="admin-filter__control" disabled>
            <option>{{ $rangeDays }}h</option>
        </select>
    </label>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card
            label="Total User"
            :value="number_format($kpis['total_users'])"
            tone="primary"
            description="Jumlah akun terdaftar." />

        <x-admin.kpi-card
            label="User Online"
            :value="number_format($kpis['online_users'])"
            tone="success"
            :description="'Aktif <= ' . \App\Services\Admin\AdminMetricsService::PRESENCE_ONLINE_MINUTES . ' menit terakhir.'" />

        <x-admin.kpi-card
            label="Aktif Hari Ini"
            :value="number_format($kpis['active_users_today'])"
            tone="gold"
            description="User dengan event atau presence hari ini." />

        <x-admin.kpi-card
            label="Aktif Minggu Ini"
            :value="number_format($kpis['active_users_week'])"
            tone="default"
            description="Aktivitas 7 hari terakhir." />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card
            label="Request AI Hari Ini"
            :value="number_format($kpis['ai_requests_today'])"
            tone="primary"
            :description="'Sukses ' . number_format($kpis['ai_success_today']) . ' / Pending ' . number_format($kpis['ai_pending_today'])" />

        <x-admin.kpi-card
            label="Error AI Hari Ini"
            :value="number_format($kpis['ai_failed_today'])"
            tone="danger"
            description="Status failed pada AI Usage Events." />

        <x-admin.kpi-card
            label="Latensi Rata-rata"
            :value="$kpis['avg_latency_ms_today'] !== null ? number_format($kpis['avg_latency_ms_today']) . ' ms' : '—'"
            tone="success"
            description="Rata-rata event sukses hari ini." />

        <x-admin.kpi-card
            label="Pesan User Hari Ini"
            :value="number_format($kpis['messages_today'])"
            tone="gold"
            :description="number_format($kpis['conversations_today']) . ' percakapan baru.'" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card
            label="Dokumen Ready"
            :value="number_format($kpis['documents_ready'])"
            tone="success" />
        <x-admin.kpi-card
            label="Dokumen Processing"
            :value="number_format($kpis['documents_processing'])"
            tone="warning" />
        <x-admin.kpi-card
            label="Dokumen Gagal"
            :value="number_format($kpis['documents_failed'])"
            tone="danger" />
        <x-admin.kpi-card
            label="Memo (Hari/Minggu)"
            :value="number_format($kpis['memos_today']) . ' / ' . number_format($kpis['memos_week'])"
            tone="primary"
            description="Memo dibuat hari ini / 7 hari terakhir." />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <x-admin.section
                title="Aktivitas {{ $rangeDays }} Hari Terakhir"
                description="Total event AI per hari berdasarkan AI Usage Events.">
                <x-slot name="actions">
                    <div class="inline-flex rounded-lg border border-stone-200 bg-white p-1 text-[11px] font-semibold uppercase tracking-wider dark:border-gray-800 dark:bg-gray-900">
                        @foreach ([7, 14, 30] as $option)
                            <button type="button"
                                    wire:click="setRange({{ $option }})"
                                    @class([
                                        'rounded-md px-2.5 py-1 transition',
                                        'bg-ista-primary text-white' => $rangeDays === $option,
                                        'text-stone-500 hover:text-ista-primary dark:text-gray-400' => $rangeDays !== $option,
                                    ])>
                                {{ $option }}h
                            </button>
                        @endforeach
                    </div>
                </x-slot>

                @if (collect($series)->sum('total') === 0)
                    <x-admin.empty-state
                        title="Belum ada aktivitas"
                        description="Event akan muncul setelah user memakai fitur AI." />
                @else
                    <div class="flex h-48 items-end gap-1.5" role="img" aria-label="Grafik aktivitas event AI per hari">
                        @foreach ($series as $point)
                            @php
                                $heightPct = $maxSeriesValue > 0 ? max(2, (int) round(($point['total'] / $maxSeriesValue) * 100)) : 0;
                                $failedPct = $point['total'] > 0 ? (int) round(($point['failed'] / $point['total']) * $heightPct) : 0;
                            @endphp
                            <div class="group flex flex-1 flex-col items-center gap-1.5">
                                <div class="relative flex w-full flex-col-reverse items-stretch overflow-hidden rounded-t-md bg-stone-100 dark:bg-gray-800" style="height: 100%">
                                    <div class="w-full bg-ista-primary/80 transition-all" style="height: {{ $heightPct }}%" title="Total: {{ $point['total'] }} (failed: {{ $point['failed'] }})"></div>
                                    @if ($failedPct > 0)
                                        <div class="absolute bottom-0 left-0 w-full bg-rose-500/80" style="height: {{ $failedPct }}%"></div>
                                    @endif
                                </div>
                                <span class="text-[9px] font-semibold uppercase tracking-wider text-stone-400 dark:text-gray-500">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d/m') }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex items-center gap-3 text-[10.5px] font-semibold uppercase tracking-wider text-stone-400 dark:text-gray-500">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-ista-primary/80"></span>Total event</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-rose-500/80"></span>Failed</span>
                    </div>
                @endif
            </x-admin.section>

            <x-admin.section
                title="Aktivitas Terbaru"
                description="10 event AI terakhir dari semua user. Tidak menampilkan isi prompt atau jawaban.">
                <x-admin.table :columns="[
                    ['key' => 'user', 'label' => 'User'],
                    ['key' => 'feature', 'label' => 'Fitur'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'latency', 'label' => 'Latensi', 'align' => 'right'],
                    ['key' => 'time', 'label' => 'Waktu', 'align' => 'right'],
                ]">
                    @forelse ($recentEvents as $event)
                        <tr>
                            <td class="admin-table__td">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ $event->user?->name ?? 'Sistem' }}</span>
                                    <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ $event->user?->email ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="admin-table__td">
                                <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $event->feature }}</span>
                            </td>
                            <td class="admin-table__td">
                                @php
                                    $tone = match ($event->status) {
                                        'success' => 'success',
                                        'error' => 'danger',
                                        'pending' => 'warning',
                                        'blocked' => 'danger',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-admin.badge :tone="$tone">{{ ucfirst($event->status) }}</x-admin.badge>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <span class="font-mono text-xs text-stone-500 dark:text-gray-400">{{ $event->latency_ms !== null ? number_format($event->latency_ms) . ' ms' : '—' }}</span>
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
            </x-admin.section>
        </div>

        <div class="space-y-4">
            <x-admin.section
                title="Distribusi Fitur"
                description="Berdasarkan event AI {{ $rangeDays }} hari terakhir.">
                @if (empty($distribution))
                    <x-admin.empty-state title="Belum ada data" description="Tidak ada event pada rentang ini." />
                @else
                    @php $totalDist = max(1, collect($distribution)->sum('total')); @endphp
                    <ul class="space-y-3 text-sm">
                        @foreach ($distribution as $row)
                            @php $pct = (int) round(($row['total'] / $totalDist) * 100); @endphp
                            <li>
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $row['feature'] }}</span>
                                    <span class="text-xs font-semibold text-stone-700 dark:text-gray-200">{{ number_format($row['total']) }}</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-gray-800">
                                    <div class="h-full bg-ista-primary/80" style="width: {{ $pct }}%"></div>
                                </div>
                                <div class="mt-1 flex items-center justify-between text-[10px] uppercase tracking-wider text-stone-400 dark:text-gray-500">
                                    <span>Sukses {{ number_format($row['success']) }}</span>
                                    <span>Gagal {{ number_format($row['failed']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.section>

            <x-admin.section
                title="Error Terbaru"
                description="5 error terakhir untuk korelasi cepat.">
                @if ($recentErrors->isEmpty())
                    <x-admin.empty-state title="Tidak ada error" description="Sistem berjalan tanpa kegagalan." />
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($recentErrors as $error)
                            <li class="rounded-lg border border-rose-100 bg-rose-50/60 p-3 dark:border-rose-900/40 dark:bg-rose-950/30">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[11px] uppercase tracking-wider text-rose-700 dark:text-rose-300">{{ $error->feature }}</span>
                                    <span class="text-[10px] uppercase tracking-wider text-rose-500 dark:text-rose-400">{{ $error->created_at?->diffForHumans() }}</span>
                                </div>
                                <p class="mt-1 text-[11px] text-rose-700 dark:text-rose-300">{{ $error->error_code ?? 'unknown_error' }}</p>
                                <p class="mt-1 truncate font-mono text-[10px] text-rose-500/80 dark:text-rose-400/80" title="{{ $error->request_id }}">req: {{ $error->request_id ?? '—' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.section>
        </div>
    </div>
</div>
