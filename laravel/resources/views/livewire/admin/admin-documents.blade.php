<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Monitoring</p>
                <h2 class="admin-page-title mt-1">Dokumen User</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Pantau status pemrosesan dokumen yang di-upload user. Halaman ini hanya menampilkan metadata file (nama, tipe, ukuran, status). Isi dokumen tidak ditampilkan.
                </p>
            </div>
            <x-admin.badge tone="neutral">Read-only</x-admin.badge>
        </div>
    </div>

    @php
        $totalDocs = array_sum($statusCounts);
        $readyCount = $statusCounts['ready'] ?? 0;
        $processingCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['processing'] ?? 0);
        $failedCount = $statusCounts['error'] ?? 0;
        $sizeLabel = $totalSizeBytes >= 1073741824
            ? number_format($totalSizeBytes / 1073741824, 2) . ' GB'
            : ($totalSizeBytes >= 1048576
                ? number_format($totalSizeBytes / 1048576, 1) . ' MB'
                : ($totalSizeBytes >= 1024
                    ? number_format($totalSizeBytes / 1024, 1) . ' KB'
                    : $totalSizeBytes . ' B'));
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card label="Total Dokumen" :value="number_format($totalDocs)" tone="primary" />
        <x-admin.kpi-card label="Ready" :value="number_format($readyCount)" tone="success" />
        <x-admin.kpi-card label="Processing" :value="number_format($processingCount)" tone="warning" />
        <x-admin.kpi-card label="Failed" :value="number_format($failedCount)" tone="danger" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card label="Total Ukuran" :value="$sizeLabel" tone="default" description="Akumulasi file_size_bytes." />
    </div>

    <div class="mt-6">
        <x-admin.section title="Filter">
            <x-slot name="actions">
                <button type="button" wire:click="resetFilters" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 transition hover:text-ista-primary dark:text-gray-400 dark:hover:text-amber-300">
                    Reset
                </button>
            </x-slot>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="admin-filter">
                    <span class="admin-filter__label">Cari nama file</span>
                    <input type="search"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Contoh: laporan.pdf"
                           class="admin-filter__control" />
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
            </div>
        </x-admin.section>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.section
                title="Dokumen Terbaru"
                description="Maksimum {{ \App\Services\Admin\AdminMetricsService::RECENT_ROWS_LIMIT }} baris.">
                @if ($documents->isEmpty())
                    <x-admin.empty-state title="Belum ada dokumen" description="Tidak ada dokumen yang cocok dengan filter saat ini." />
                @else
                    <x-admin.table :columns="[
                        ['key' => 'file', 'label' => 'File'],
                        ['key' => 'user', 'label' => 'User'],
                        ['key' => 'mime', 'label' => 'Tipe'],
                        ['key' => 'size', 'label' => 'Ukuran', 'align' => 'right'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'time', 'label' => 'Dibuat', 'align' => 'right'],
                    ]">
                        @foreach ($documents as $doc)
                            @php
                                $tone = match ($doc->status) {
                                    'ready' => 'success',
                                    'pending', 'processing' => 'warning',
                                    'error' => 'danger',
                                    default => 'neutral',
                                };
                            @endphp
                            <tr>
                                <td class="admin-table__td">
                                    <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit((string) $doc->original_name, 60, '…') }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <div class="flex flex-col">
                                        <span class="text-xs text-stone-600 dark:text-gray-300">{{ $doc->user?->name ?? '—' }}</span>
                                        <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ $doc->user?->email ?? '' }}</span>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <span class="font-mono text-[10.5px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $doc->mime_type ?? 'unknown' }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="font-mono text-xs text-stone-500 dark:text-gray-400">{{ $doc->formatted_size }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <x-admin.badge :tone="$tone">{{ ucfirst($doc->status ?? 'unknown') }}</x-admin.badge>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $doc->created_at?->toDateTimeString() }}">{{ $doc->created_at?->diffForHumans() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.section>
        </div>

        <x-admin.section title="Distribusi Tipe" description="Berdasarkan mime_type.">
            @if (empty($mimeCounts))
                <x-admin.empty-state title="Tidak ada data" />
            @else
                @php $totalMime = max(1, array_sum($mimeCounts)); @endphp
                <ul class="space-y-3 text-sm">
                    @foreach ($mimeCounts as $mime => $count)
                        @php $pct = (int) round(($count / $totalMime) * 100); @endphp
                        <li>
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-[10.5px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $mime }}</span>
                                <span class="text-xs font-semibold text-stone-700 dark:text-gray-200">{{ number_format($count) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-gray-800">
                                <div class="h-full bg-ista-primary/80" style="width: {{ $pct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.section>
    </div>
</div>
