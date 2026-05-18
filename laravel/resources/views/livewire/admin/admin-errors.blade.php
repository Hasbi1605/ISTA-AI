<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Monitoring</p>
                <h2 class="admin-page-title mt-1">Error Operasional AI</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Lihat event AI dengan status error atau blocked. Ringkasan kategori membantu korelasi cepat dengan log produksi melalui request ID.
                </p>
            </div>
            <x-admin.badge tone="danger">Failed only</x-admin.badge>
        </div>
    </div>

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
                <span class="admin-filter__label">Request ID</span>
                <input type="search"
                       wire:model.live.debounce.400ms="requestId"
                       placeholder="UUID partial…"
                       class="admin-filter__control" />
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

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.section
                title="Error Terbaru"
                description="Maksimum {{ \App\Services\Admin\AdminMetricsService::RECENT_ROWS_LIMIT }} baris.">
                @if ($errors->isEmpty())
                    <x-admin.empty-state
                        title="Tidak ada error"
                        description="Sistem berjalan tanpa kegagalan untuk filter saat ini." />
                @else
                    <x-admin.table :columns="[
                        ['key' => 'time', 'label' => 'Waktu'],
                        ['key' => 'user', 'label' => 'User'],
                        ['key' => 'feature', 'label' => 'Fitur'],
                        ['key' => 'error', 'label' => 'Kode Error'],
                        ['key' => 'request', 'label' => 'Request ID'],
                    ]">
                        @foreach ($errors as $error)
                            <tr>
                                <td class="admin-table__td">
                                    <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $error->created_at?->toDateTimeString() }}">{{ $error->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ $error->user?->name ?? 'Sistem' }}</span>
                                        <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ $error->user?->email ?? '—' }}</span>
                                    </div>
                                </td>
                                <td class="admin-table__td">
                                    <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $error->feature }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <x-admin.badge tone="danger">{{ $error->error_code ?? 'unknown_error' }}</x-admin.badge>
                                </td>
                                <td class="admin-table__td">
                                    <code class="rounded bg-stone-100 px-2 py-0.5 font-mono text-[10px] text-stone-600 dark:bg-gray-800 dark:text-gray-300" title="{{ $error->request_id }}">{{ $error->request_id ?? '—' }}</code>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.section>
        </div>

        <div class="space-y-4">
            <x-admin.section title="Berdasarkan Fitur" description="Distribusi error pada hasil saat ini.">
                @if ($byFeature->isEmpty())
                    <x-admin.empty-state title="Tidak ada error" />
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($byFeature as $feature => $count)
                            <li class="flex items-center justify-between">
                                <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $feature }}</span>
                                <span class="font-semibold text-stone-700 dark:text-gray-200">{{ number_format($count) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.section>

            <x-admin.section title="Berdasarkan Kode Error">
                @if ($byCode->isEmpty())
                    <x-admin.empty-state title="Tidak ada kode error" description="Belum ada event dengan error_code tersanitasi." />
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($byCode as $code => $count)
                            <li class="flex items-center justify-between">
                                <span class="font-mono text-[11px] uppercase tracking-wider text-rose-700 dark:text-rose-300">{{ $code }}</span>
                                <span class="font-semibold text-stone-700 dark:text-gray-200">{{ number_format($count) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.section>
        </div>
    </div>
</div>
