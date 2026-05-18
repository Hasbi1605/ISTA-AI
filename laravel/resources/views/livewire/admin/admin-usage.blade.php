<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Monitoring</p>
                <h2 class="admin-page-title mt-1">AI Usage Events</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Lihat event AI per fitur, status, dan rentang tanggal. Hanya metadata operasional yang ditampilkan.
                </p>
            </div>
            <x-admin.badge tone="neutral">Read-only</x-admin.badge>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card label="Total Event" :value="number_format($totals['total'])" tone="primary" />
        <x-admin.kpi-card label="Sukses" :value="number_format($totals['success'])" tone="success" />
        <x-admin.kpi-card label="Pending" :value="number_format($totals['pending'])" tone="warning" />
        <x-admin.kpi-card label="Gagal" :value="number_format($totals['failed'])" tone="danger" />
    </div>

    <div class="mt-6">
        <x-admin.section title="Filter">
            <x-slot name="actions">
                <button type="button" wire:click="resetFilters" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 transition hover:text-ista-primary dark:text-gray-400 dark:hover:text-amber-300">
                    Reset
                </button>
            </x-slot>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="admin-filter">
                    <span class="admin-filter__label">Fitur</span>
                    <select wire:model.live="feature" class="admin-filter__control">
                        <option value="">Semua</option>
                        @foreach ($featureOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Status</span>
                    <select wire:model.live="status" class="admin-filter__control">
                        <option value="">Semua</option>
                        @foreach ($statusOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Tanggal Mulai</span>
                    <input type="date" wire:model.live="startDate" class="admin-filter__control" />
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Tanggal Akhir</span>
                    <input type="date" wire:model.live="endDate" class="admin-filter__control" />
                </label>
            </div>
        </x-admin.section>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.section
                title="Event Terbaru"
                description="Maksimum {{ \App\Services\Admin\AdminMetricsService::RECENT_ROWS_LIMIT }} baris pada rentang yang dipilih.">
                @if ($events->isEmpty())
                    <x-admin.empty-state
                        title="Belum ada event"
                        description="Tidak ada event AI pada rentang dan filter saat ini." />
                @else
                    <x-admin.table :columns="[
                        ['key' => 'user', 'label' => 'User'],
                        ['key' => 'feature', 'label' => 'Fitur'],
                        ['key' => 'action', 'label' => 'Action'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'latency', 'label' => 'Latensi', 'align' => 'right'],
                        ['key' => 'request_id', 'label' => 'Request'],
                        ['key' => 'time', 'label' => 'Waktu', 'align' => 'right'],
                    ]">
                        @foreach ($events as $event)
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
                                    <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $event->action }}</span>
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
                                <td class="admin-table__td">
                                    <code class="rounded bg-stone-100 px-2 py-0.5 font-mono text-[10px] text-stone-600 dark:bg-gray-800 dark:text-gray-300" title="{{ $event->request_id }}">{{ $event->request_id ?? '—' }}</code>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $event->created_at?->toDateTimeString() }}">{{ $event->created_at?->diffForHumans() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.section>
        </div>

        <x-admin.section
            title="Distribusi Fitur"
            description="Berdasarkan rentang yang dipilih.">
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
    </div>
</div>
