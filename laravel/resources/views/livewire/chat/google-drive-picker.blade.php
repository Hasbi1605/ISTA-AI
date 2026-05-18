<div x-data="{ visible: @entangle('isOpen').live }"
     x-on:open-google-drive-picker.window="$wire.open()"
     x-on:keydown.escape.window="if (visible) $wire.close()">
    <div x-show="visible"
         x-transition.opacity
         class="fixed inset-0 z-[70] flex items-center justify-center px-4 py-6 sm:px-6"
         style="{{ $isOpen ? '' : 'display: none;' }}"
         role="dialog"
         aria-modal="true"
         aria-labelledby="google-drive-picker-title">
        <div class="absolute inset-0 bg-gray-950/70 backdrop-blur-sm"
             @click="$wire.close()"
             aria-hidden="true"></div>

        <section class="relative flex max-h-[88vh] w-full max-w-5xl flex-col overflow-hidden rounded-[28px] border border-stone-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
            <header class="border-b border-stone-200 px-5 py-5 dark:border-gray-800 sm:px-6">
                <div class="flex items-start gap-4">
                    <div class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-stone-100 text-stone-700 dark:bg-gray-800 dark:text-gray-200">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.28em] text-stone-500 dark:text-gray-400">Drive Kantor</p>
                        <h2 id="google-drive-picker-title" class="mt-2 text-2xl font-semibold tracking-tight text-stone-900 dark:text-gray-100 sm:text-[28px]">
                            Pilih file untuk chat
                        </h2>
                        <p class="mt-1.5 max-w-2xl text-sm text-stone-600 dark:text-gray-400">
                            File yang dipilih akan langsung masuk ke dokumen rujukan chat.
                        </p>
                    </div>

                    <button type="button"
                            wire:click="close"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-stone-500 transition hover:bg-stone-100 hover:text-stone-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                            aria-label="Tutup Google Drive picker">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                @if (! $isConfigured)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <p class="font-semibold">Google Drive belum siap</p>
                        <p class="mt-1 text-sm leading-6">Koneksi Google Drive kantor belum tersedia. Coba beberapa saat lagi atau gunakan unggah file biasa.</p>
                        <button type="button" wire:click="retryLoad" class="mt-4 rounded-xl bg-amber-700 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-800">Cek ulang koneksi Drive</button>
                    </div>
                @else
                    <div class="rounded-2xl border border-stone-200/80 bg-stone-50/80 p-4 dark:border-gray-800 dark:bg-gray-950/60">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-stone-500 dark:text-gray-400">Folder aktif</p>
                                <ol class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                    @foreach ($breadcrumb as $index => $crumb)
                                        <li class="flex min-w-0 items-center gap-2">
                                            @if ($index > 0)
                                                <svg class="h-3.5 w-3.5 shrink-0 text-stone-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" />
                                                </svg>
                                            @endif

                                            <button type="button"
                                                    wire:click="goToBreadcrumb({{ $index }})"
                                                    class="{{ $loop->last ? 'border border-stone-200 bg-white text-stone-900 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100' : 'bg-transparent text-stone-600 hover:bg-white hover:text-stone-900 dark:text-gray-300 dark:hover:bg-gray-900 dark:hover:text-white' }} inline-flex max-w-full items-center rounded-full px-3 py-1.5 font-medium transition">
                                                <span class="truncate">{{ $crumb['name'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button"
                                        wire:click="previousPage"
                                        @disabled(empty($pageTokenStack))
                                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-stone-300 text-stone-600 transition hover:bg-white hover:text-stone-900 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900 dark:hover:text-white"
                                        title="Halaman sebelumnya"
                                        aria-label="Halaman sebelumnya">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M11.78 14.78a.75.75 0 0 1-1.06 0L6.47 10.53a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 1 1 1.06 1.06L8.06 10l3.72 3.72a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <button type="button"
                                        wire:click="nextPage"
                                        @disabled(blank($nextPageToken))
                                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-ista-primary text-white transition hover:bg-stone-800 disabled:cursor-not-allowed disabled:bg-stone-200 disabled:text-stone-400 disabled:hover:bg-stone-200 dark:disabled:bg-gray-800 dark:disabled:text-gray-600"
                                        title="Halaman berikutnya"
                                        aria-label="Halaman berikutnya">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 1 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <label class="sr-only" for="chat-google-drive-search">Cari file atau folder</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-stone-400 dark:text-gray-500">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 1 0 3.47 9.77l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 8.5 3ZM4.5 8.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <input id="chat-google-drive-search"
                                   type="text"
                                   wire:model.live.debounce.350ms="search"
                                   placeholder="Cari file atau folder"
                                   class="w-full rounded-2xl border border-stone-300 bg-white py-3 pl-11 pr-4 text-sm outline-none transition focus:border-ista-primary focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" />
                        </div>
                    </div>

                    <div wire:loading.flex class="mt-4 items-center gap-2 px-1 text-sm text-stone-500 dark:text-gray-400" role="status" aria-live="polite">
                        <span class="h-4 w-4 rounded-full border-2 border-current border-t-transparent animate-spin"></span>
                        <span>Memuat file...</span>
                    </div>

                    @if ($statusMessage || $errorMessage)
                        <div class="mt-5 space-y-3">
                            @if ($statusMessage)
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
                                    {{ $statusMessage }}
                                </div>
                            @endif

                            @if ($errorMessage)
                                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100" role="alert">
                                    <p>{{ $errorMessage }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" wire:click="retryLoad" class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-800">Coba muat ulang</button>
                                        <span class="rounded-xl border border-rose-200 bg-white/70 px-3 py-2 text-xs font-semibold text-rose-800 dark:border-rose-400/30 dark:bg-rose-950/20 dark:text-rose-100">Jika masih gagal, coba lagi nanti atau gunakan unggah dokumen dari perangkat Anda.</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-6 grid gap-3 lg:grid-cols-2">
                        @forelse ($items as $item)
                            @php
                                $isFolder = (bool) ($item['is_folder'] ?? false);
                                $isProcessable = (bool) ($item['is_processable'] ?? false);
                                $isWorkspaceFile = (bool) ($item['is_google_workspace_file'] ?? false);
                                $isShortcut = (bool) ($item['is_shortcut'] ?? false);
                                $unsupportedReason = trim((string) ($item['unsupported_reason'] ?? ''));
                                $sizeBytes = $item['size_bytes'] ?? null;
                                $sizeLabel = $sizeBytes !== null
                                    ? number_format(max(((int) $sizeBytes) / 1024, 0.1), 1).' KB'
                                    : null;
                                $modifiedLabel = ! empty($item['modified_time'])
                                    ? \Illuminate\Support\Carbon::parse($item['modified_time'])->format('d M Y')
                                    : null;
                                $extension = strtoupper((string) pathinfo((string) ($item['name'] ?? ''), PATHINFO_EXTENSION));
                                $metaParts = $isFolder
                                    ? []
                                    : [$isShortcut ? 'Shortcut' : ($isWorkspaceFile ? 'Google Workspace' : ($extension !== '' ? $extension : 'File'))];

                                if (! $isFolder && $sizeLabel !== null) {
                                    $metaParts[] = $sizeLabel;
                                }

                                if ($modifiedLabel !== null) {
                                    $metaParts[] = $modifiedLabel;
                                }

                                $metaLabel = implode(' · ', array_filter($metaParts));
                            @endphp

                            <article class="group flex h-full flex-col justify-between rounded-2xl border border-stone-200 bg-white p-4 shadow-sm shadow-stone-200/50 transition hover:-translate-y-[1px] hover:border-ista-primary/30 dark:border-gray-800 dark:bg-gray-900 dark:shadow-none">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $isFolder ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' : ($isWorkspaceFile ? 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300' : 'bg-stone-100 text-stone-600 dark:bg-gray-800 dark:text-gray-300') }}">
                                        @if ($isFolder)
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                                            </svg>
                                        @else
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 3v5h5" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 13h6M9 17h6" />
                                            </svg>
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h3 class="truncate text-[15px] font-semibold text-stone-900 dark:text-gray-100">{{ $item['name'] }}</h3>
                                                @if ($metaLabel !== '')
                                                    <p class="mt-1 text-xs text-stone-500 dark:text-gray-400">{{ $metaLabel }}</p>
                                                @endif
                                            </div>

                                            @if ($isFolder)
                                                <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                                    Folder
                                                </span>
                                            @elseif ($isWorkspaceFile)
                                                <span class="shrink-0 rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                                                    Workspace
                                                </span>
                                            @elseif ($isShortcut)
                                                <span class="shrink-0 rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold text-stone-600 dark:bg-gray-800 dark:text-gray-300">
                                                    Shortcut
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between gap-2">
                                    @if ($isFolder)
                                        <span class="inline-flex h-10 w-10 shrink-0"></span>
                                        <button type="button"
                                                wire:click="goToFolder(@js($item['id']), @js($item['name']))"
                                                class="inline-flex items-center gap-2 rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-ista-primary dark:bg-white dark:text-stone-900 dark:hover:bg-stone-200">
                                            Buka
                                        </button>
                                    @else
                                        @if (! empty($item['web_view_link']))
                                            <a href="{{ $item['web_view_link'] }}"
                                               target="_blank"
                                               rel="noreferrer"
                                               class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-stone-300 text-stone-600 transition hover:bg-stone-100 hover:text-stone-900 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                                               title="Buka di Drive"
                                               aria-label="Buka {{ $item['name'] }} di Google Drive">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M11.5 3.75a.75.75 0 0 0 0 1.5h2.19l-5.97 5.97a.75.75 0 1 0 1.06 1.06l5.97-5.97v2.19a.75.75 0 0 0 1.5 0v-4a.75.75 0 0 0-.75-.75h-4Z" />
                                                    <path d="M5.75 5A2.75 2.75 0 0 0 3 7.75v6.5A2.75 2.75 0 0 0 5.75 17h6.5A2.75 2.75 0 0 0 15 14.25V11.5a.75.75 0 0 0-1.5 0v2.75c0 .69-.56 1.25-1.25 1.25h-6.5c-.69 0-1.25-.56-1.25-1.25v-6.5c0-.69.56-1.25 1.25-1.25H8.5A.75.75 0 0 0 8.5 5H5.75Z" />
                                                </svg>
                                            </a>
                                        @else
                                            <span class="inline-flex h-10 w-10 shrink-0"></span>
                                        @endif

                                        <button type="button"
                                                wire:click="processFile(@js($item['id']))"
                                                wire:loading.attr="disabled"
                                                wire:target="processFile(@js($item['id']))"
                                                @disabled(! $isProcessable)
                                                title="{{ ! $isProcessable && $unsupportedReason !== '' ? $unsupportedReason : '' }}"
                                                class="inline-flex min-w-[116px] items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 {{ $isProcessable ? 'bg-ista-primary text-white hover:bg-stone-800' : 'bg-stone-100 text-stone-400 dark:bg-gray-800 dark:text-gray-500' }}">
                                            <span wire:loading.remove wire:target="processFile(@js($item['id']))">{{ $isProcessable ? 'Pakai untuk chat' : ($isShortcut ? 'Shortcut belum didukung' : 'Belum bisa dipakai') }}</span>
                                            <span wire:loading.inline-flex wire:target="processFile(@js($item['id']))" class="items-center gap-2">
                                                <span class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin"></span>
                                                Memproses
                                            </span>
                                        </button>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-sm text-stone-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 lg:col-span-2">
                                @if ($search !== '')
                                    Tidak ada file yang cocok dengan pencarian “{{ $search }}”. Hapus kata kunci atau coba folder lain.
                                @else
                                    Folder ini belum berisi dokumen yang bisa dipakai untuk chat.
                                @endif
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>
