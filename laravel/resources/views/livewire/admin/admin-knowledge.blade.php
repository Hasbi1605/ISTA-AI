<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Knowledge</p>
                <h2 class="admin-page-title mt-1">Knowledge Base Internal</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Kelola dokumen knowledge internal global. Dokumen di-upload admin akan diproses menjadi vector dengan metadata <code class="font-mono text-[11px]">scope=global_internal</code> dan <code class="font-mono text-[11px]">audience=all_users</code>. Halaman ini hanya menampilkan metadata file, bukan isinya.
                </p>
            </div>
            <x-admin.badge tone="primary">Admin only</x-admin.badge>
        </div>
    </div>

    @if (session('knowledge_status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">
            {{ session('knowledge_status') }}
        </div>
    @endif

    @php
        $totalDocs = array_sum($statusCounts);
        $activeCount = $statusCounts['active'] ?? 0;
        $processingCount = ($statusCounts['draft'] ?? 0) + ($statusCounts['processing'] ?? 0);
        $errorCount = $statusCounts['error'] ?? 0;
        $archivedCount = $statusCounts['archived'] ?? 0;
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card label="Total Knowledge" :value="number_format($totalDocs)" tone="primary" />
        <x-admin.kpi-card label="Active" :value="number_format($activeCount)" tone="success" />
        <x-admin.kpi-card label="Draft / Processing" :value="number_format($processingCount)" tone="warning" />
        <x-admin.kpi-card label="Error" :value="number_format($errorCount)" tone="danger" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card label="Archived" :value="number_format($archivedCount)" tone="default" description="Dokumen yang dinonaktifkan." />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.section title="Filter">
                <x-slot name="actions">
                    <button type="button"
                            wire:click="resetFilters"
                            class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 transition hover:text-ista-primary dark:text-gray-400 dark:hover:text-amber-300">
                        Reset
                    </button>
                </x-slot>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="admin-filter">
                        <span class="admin-filter__label">Cari judul / nama file</span>
                        <input type="search"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Contoh: SOP HR"
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

                    <label class="admin-filter">
                        <span class="admin-filter__label">Source</span>
                        <select wire:model.live="sourceFilter" class="admin-filter__control">
                            <option value="">Semua</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </x-admin.section>
        </div>

        <x-admin.section title="Upload Dokumen Knowledge" description="Format yang diterima: {{ implode(', ', $allowedExtensions) }}.">
            <form wire:submit.prevent="upload" class="space-y-3">
                <label class="admin-filter">
                    <span class="admin-filter__label">Judul (opsional)</span>
                    <input type="text"
                           wire:model.defer="title"
                           placeholder="Contoh: SOP Penerimaan Tamu"
                           class="admin-filter__control" />
                    @error('title') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Source (existing)</span>
                    <select wire:model.defer="sourceId" class="admin-filter__control">
                        <option value="">— Pilih source —</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }}</option>
                        @endforeach
                    </select>
                    @error('sourceId') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Atau buat source baru</span>
                    <input type="text"
                           wire:model.defer="newSourceName"
                           placeholder="Contoh: Aturan ISTANA"
                           class="admin-filter__control" />
                    @error('newSourceName') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">Catatan internal (opsional)</span>
                    <textarea wire:model.defer="notes"
                              rows="2"
                              placeholder="Konteks tambahan untuk admin lain"
                              class="admin-filter__control"></textarea>
                    @error('notes') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </label>

                <label class="admin-filter">
                    <span class="admin-filter__label">File knowledge</span>
                    <input type="file"
                           wire:model="file"
                           accept=".pdf,.docx,.xlsx,.csv"
                           class="admin-filter__control" />
                    @error('file') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </label>

                <div wire:loading wire:target="file" class="text-xs text-stone-500 dark:text-gray-400">Meng-upload file…</div>

                <button type="submit"
                        class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-ista-primary px-4 text-xs font-semibold uppercase tracking-wider text-amber-200 transition hover:bg-ista-primary/90 disabled:opacity-50"
                        wire:loading.attr="disabled"
                        wire:target="upload,file">
                    <span wire:loading.remove wire:target="upload">Upload Knowledge</span>
                    <span wire:loading wire:target="upload">Memproses…</span>
                </button>
            </form>
        </x-admin.section>
    </div>

    <div class="mt-6">
        <x-admin.section
            title="Daftar Knowledge Internal"
            description="Maksimum 100 baris. Status menunjukkan apakah knowledge aktif atau di-archive.">
            @if ($documents->isEmpty())
                <x-admin.empty-state title="Belum ada knowledge" description="Belum ada dokumen knowledge yang cocok dengan filter saat ini." />
            @else
                <x-admin.table :columns="[
                    ['key' => 'title', 'label' => 'Judul / File'],
                    ['key' => 'source', 'label' => 'Source'],
                    ['key' => 'mime', 'label' => 'Tipe'],
                    ['key' => 'size', 'label' => 'Ukuran', 'align' => 'right'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'time', 'label' => 'Dibuat', 'align' => 'right'],
                    ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right'],
                ]">
                    @foreach ($documents as $doc)
                        @php
                            $tone = match ($doc->status) {
                                'active' => 'success',
                                'draft', 'processing' => 'warning',
                                'error' => 'danger',
                                'archived' => 'neutral',
                                default => 'neutral',
                            };
                        @endphp
                        <tr>
                            <td class="admin-table__td">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit((string) $doc->title, 60, '…') }}</span>
                                    <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ \Illuminate\Support\Str::limit((string) $doc->original_name, 60, '…') }}</span>
                                </div>
                            </td>
                            <td class="admin-table__td">
                                <span class="text-xs text-stone-600 dark:text-gray-300">{{ $doc->source?->name ?? '—' }}</span>
                            </td>
                            <td class="admin-table__td">
                                <span class="font-mono text-[10.5px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $doc->mime_type ?? 'unknown' }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <span class="font-mono text-xs text-stone-500 dark:text-gray-400">{{ $doc->formatted_size }}</span>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$tone">{{ ucfirst($doc->status) }}</x-admin.badge>
                                @if ($doc->error_code)
                                    <p class="mt-1 text-[10.5px] text-rose-500 dark:text-rose-300">{{ $doc->error_code }}</p>
                                @endif
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $doc->created_at?->toDateTimeString() }}">{{ $doc->created_at?->diffForHumans() }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($doc->status !== 'active')
                                        <button type="button"
                                                wire:click="activate({{ $doc->id }})"
                                                class="text-[11px] font-semibold uppercase tracking-wider text-emerald-600 transition hover:text-emerald-500 dark:text-emerald-300">
                                            Activate
                                        </button>
                                    @endif
                                    @if ($doc->status !== 'archived')
                                        <button type="button"
                                                wire:click="archive({{ $doc->id }})"
                                                class="text-[11px] font-semibold uppercase tracking-wider text-amber-600 transition hover:text-amber-500 dark:text-amber-300">
                                            Archive
                                        </button>
                                    @endif
                                    <button type="button"
                                            wire:click="reprocess({{ $doc->id }})"
                                            class="text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:text-ista-primary dark:text-gray-300 dark:hover:text-amber-300">
                                        Reprocess
                                    </button>
                                    <button type="button"
                                            wire:click="delete({{ $doc->id }})"
                                            wire:confirm="Yakin hapus knowledge ini? Vector akan dihapus juga."
                                            class="text-[11px] font-semibold uppercase tracking-wider text-rose-600 transition hover:text-rose-500 dark:text-rose-300">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.section>
    </div>
</div>
