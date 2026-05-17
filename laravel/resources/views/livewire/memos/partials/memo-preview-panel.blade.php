{{-- Memo Document Panel (Right Column, flex-1) --}}
<div x-show="!isMobile || memoMobilePanel === 'document'" x-cloak class="flex-1 flex flex-col min-w-0 bg-stone-50 dark:bg-gray-950 overflow-hidden">

    {{-- Document Header --}}
    <div class="relative z-30 min-h-[61px] flex-shrink-0 flex items-center justify-between gap-2 px-3 sm:px-5 border-b border-stone-200/60 bg-white/85 backdrop-blur-sm dark:border-[#1E293B]/70 dark:bg-gray-800/85">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" @click="showMemoChatPanel()" class="inline-flex items-center justify-center rounded-lg border border-stone-200 bg-white p-2 text-stone-600 shadow-sm transition hover:bg-stone-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 lg:hidden" aria-label="Kembali ke chat memo" title="Kembali">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <div class="inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 py-1 text-[12.5px] font-semibold text-stone-800 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span class="hidden sm:inline">Dokumen</span>
            </div>
        </div>

        {{-- Actions --}}
        @if ($activeMemoId)
            @php
                $memoDownloadRouteParameters = array_filter([
                    'memo' => $activeMemoId,
                    'version_id' => $activeMemoVersionId,
                ], fn ($value) => filled($value));
            @endphp
            <div
                class="flex flex-shrink-0 items-center gap-1.5 sm:gap-2"
                x-data="memoDocumentDownloads"
            >
                <button type="button" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate,generateRevisionFromChat,generateFromChat"
                        class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg border border-stone-200 dark:border-gray-700 text-[12px] font-semibold text-stone-600 dark:text-gray-300 hover:bg-stone-100 dark:hover:bg-gray-800 transition-all disabled:opacity-50"
                        aria-label="Regenerate dokumen" title="Regenerate">
                    <span wire:loading wire:target="regenerate,generateRevisionFromChat" class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                    <svg wire:loading.remove wire:target="regenerate,generateRevisionFromChat" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span class="hidden sm:inline">Regenerate</span>
                </button>
                    <button type="button"
                            data-download-url="{{ route('memos.download', $memoDownloadRouteParameters) }}"
                            data-force-save-url="{{ route('memos.force-save', ['memo' => $activeMemoId]) }}"
                            data-download-version-id="{{ $activeMemoVersionId }}"
                            data-download-filename="{{ e(($title ?: 'memo').'.docx') }}"
                            @click="downloadMemo($el.dataset.downloadUrl, 'docx', $el.dataset.downloadFilename, $el.dataset.downloadVersionId, $el.dataset.forceSaveUrl)"
                        wire:loading.attr="disabled"
                        wire:target="switchMemoVersion,generateRevisionFromChat,generateFromChat,generateConfiguredMemo"
                        :disabled="downloadLoading !== null"
                        class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg border border-stone-200 dark:border-gray-700 text-[12px] font-semibold text-stone-600 dark:text-gray-300 hover:bg-stone-100 dark:hover:bg-gray-800 transition-all disabled:cursor-not-allowed disabled:opacity-60"
                        aria-label="Unduh DOCX" title="Unduh DOCX">
                    <span x-show="downloadLoading === 'docx'" style="display:none;" class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                    <svg x-show="downloadLoading !== 'docx'" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="hidden sm:inline">DOCX</span>
                </button>
                    <button type="button"
                            data-download-url="{{ route('memos.export.pdf', $memoDownloadRouteParameters) }}"
                            data-force-save-url="{{ route('memos.force-save', ['memo' => $activeMemoId]) }}"
                            data-download-version-id="{{ $activeMemoVersionId }}"
                            data-download-filename="{{ e(($title ?: 'memo').'.pdf') }}"
                            @click="downloadMemo($el.dataset.downloadUrl, 'pdf', $el.dataset.downloadFilename, $el.dataset.downloadVersionId, $el.dataset.forceSaveUrl)"
                        wire:loading.attr="disabled"
                        wire:target="switchMemoVersion,generateRevisionFromChat,generateFromChat,generateConfiguredMemo"
                        :disabled="downloadLoading !== null"
                        class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg bg-ista-primary text-[12px] font-semibold text-white hover:bg-ista-dark transition-all disabled:cursor-not-allowed disabled:opacity-70"
                        aria-label="Unduh PDF" title="Unduh PDF">
                    <span x-show="downloadLoading === 'pdf'" style="display:none;" class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                    <svg x-show="downloadLoading !== 'pdf'" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="hidden sm:inline">PDF</span>
                </button>
                <span x-show="downloadStatus || downloadError" x-cloak x-text="downloadStatus || downloadError" class="hidden max-w-[190px] truncate text-[11px] font-medium text-stone-500 dark:text-gray-400 md:inline" :class="{ 'text-amber-700 dark:text-amber-200': downloadError }" role="status" aria-live="polite"></span>
            </div>
        @endif
    </div>

    {{-- Content Area --}}
    <div class="flex-1 overflow-y-auto">
        @if ($editorConfig)
            <div
                wire:ignore
                wire:key="memo-editor-{{ $activeMemoId }}-{{ $activeMemoVersionId ?? 'current' }}-{{ md5($editorConfig['document']['key'] ?? '') }}"
                class="h-full min-h-[640px]"
                x-data="{
                    config: @js($editorConfig),
                    apiUrl: @js($onlyOfficeApiUrl),
                    containerId: 'memo-workspace-editor-{{ md5($editorConfig['document']['key'] ?? '') }}',
                    editor: null,
                    editorFailed: false,
                    load() {
                        this.editorFailed = false;
                        this.destroy();
                        const boot = () => {
                            try {
                                const container = document.getElementById(this.containerId);
                                if (container) { container.innerHTML = ''; }
                                const documentKey = this.config?.document?.key || this.containerId;
                                window.memoOnlyOfficeState = window.memoOnlyOfficeState || {};
                                window.memoOnlyOfficeState[documentKey] = {
                                    containerId: this.containerId,
                                    dirty: false,
                                    lastChangeAt: 0,
                                    lastReadyAt: Date.now(),
                                };
                                const existingEvents = this.config.events || {};
                                this.config.events = {
                                    ...existingEvents,
                                    onDocumentReady: (...args) => {
                                        window.memoOnlyOfficeState[documentKey] = {
                                            ...(window.memoOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            dirty: false,
                                            lastReadyAt: Date.now(),
                                        };
                                        existingEvents.onDocumentReady?.(...args);
                                    },
                                    onDocumentStateChange: (event) => {
                                        window.memoOnlyOfficeState[documentKey] = {
                                            ...(window.memoOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            dirty: Boolean(event?.data),
                                            lastChangeAt: Date.now(),
                                        };
                                        existingEvents.onDocumentStateChange?.(event);
                                    },
                                    onError: (...args) => {
                                        window.memoOnlyOfficeState[documentKey] = {
                                            ...(window.memoOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            lastErrorAt: Date.now(),
                                        };
                                        existingEvents.onError?.(...args);
                                    },
                                };
                                this.editor = new DocsAPI.DocEditor(this.containerId, this.config);
                            } catch (error) {
                                console.error('OnlyOffice editor gagal dimuat', error);
                                this.editorFailed = true;
                            }
                        };
                        if (window.DocsAPI) { boot(); return; }
                        const script = document.createElement('script');
                        script.src = this.apiUrl;
                        script.onload = boot;
                        script.onerror = () => { this.editorFailed = true; };
                        document.head.appendChild(script);
                    },
                    destroy() {
                        if (this.editor && typeof this.editor.destroyEditor === 'function') {
                            this.editor.destroyEditor();
                        }
                        const documentKey = this.config?.document?.key || this.containerId;
                        if (window.memoOnlyOfficeState?.[documentKey]) {
                            window.memoOnlyOfficeState[documentKey].destroyedAt = Date.now();
                        }
                        this.editor = null;
                    }
                }"
                x-init="load()"
            >
                <div id="memo-workspace-editor-{{ md5($editorConfig['document']['key'] ?? '') }}" class="h-full min-h-[640px] w-full"></div>
                <div x-show="editorFailed" x-transition class="flex min-h-[640px] items-center justify-center px-6 text-center" style="display:none;">
                    <div class="max-w-md rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <p class="font-semibold">Editor dokumen belum bisa dimuat.</p>
                        <p class="mt-2 text-sm leading-6">Periksa koneksi ke OnlyOffice, lalu coba muat ulang editor. Anda tetap bisa mengunduh DOCX/PDF dari tombol di atas bila dokumen sudah tersedia.</p>
                        <button type="button" @click="load()" class="mt-4 rounded-lg bg-amber-700 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-800">Coba muat ulang editor</button>
                    </div>
                </div>
            </div>
        @else
            <div wire:loading.flex wire:target="generateConfiguredMemo,generateFromChat" class="h-full min-h-[400px] items-center justify-center px-6 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.75)] dark:bg-gray-900">
                        <div class="relative flex h-12 w-12 items-center justify-center rounded-full">
                            <span class="absolute inset-0 rounded-full border-2 border-ista-primary/25 border-t-ista-primary animate-spin"></span>
                            <img src="{{ asset('images/ista/logo.png') }}" alt="" class="h-8 w-8 object-contain" />
                        </div>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Sedang membuat memo...</h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-stone-500 dark:text-gray-400">
                        Dokumen sedang disusun dan akan tampil otomatis di panel ini.
                    </p>
                </div>
            </div>

            <div wire:loading.remove wire:target="generateConfiguredMemo,generateFromChat" class="flex h-full min-h-[400px] items-center justify-center px-6 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto h-16 w-16 rounded-2xl bg-stone-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-stone-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">{{ $activeMemoId ? 'Draft dokumen belum tersedia' : 'Belum ada memo aktif' }}</h3>
                    <p class="mt-2 text-[13px] text-stone-500 dark:text-gray-400 leading-relaxed">
                        @if ($activeMemoId)
                            Memo aktif belum memiliki draft yang bisa dibuka. Buat ulang dari konfigurasi atau coba muat memo lain.
                        @else
                            Pilih memo dari riwayat atau lengkapi konfigurasi untuk membuat memo pertama. Dokumen DOCX akan tampil di sini melalui OnlyOffice.
                        @endif
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>
