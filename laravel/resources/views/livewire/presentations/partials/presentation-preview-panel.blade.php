@php
    $activePreviewPresentation = $previewPresentation;
    $previewBadge = function (?string $status) {
        return match($status) {
            \App\Models\Presentation::STATUS_READY, \App\Models\Presentation::STATUS_EDITED => ['Siap', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
            \App\Models\Presentation::STATUS_PROCESSING => ['Diproses', 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
            \App\Models\Presentation::STATUS_ERROR => ['Gagal', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
            \App\Models\Presentation::STATUS_PENDING => ['Dalam antrean', 'bg-stone-100 text-stone-600 dark:bg-gray-700 dark:text-gray-300'],
            default => ['Belum aktif', 'bg-stone-100 text-stone-600 dark:bg-gray-700 dark:text-gray-300'],
        };
    };
    $isPreviewInProgress = $activePreviewPresentation
        && in_array($activePreviewPresentation->status, [\App\Models\Presentation::STATUS_PENDING, \App\Models\Presentation::STATUS_PROCESSING], true);
    $isPreviewStale = $isPreviewInProgress
        && ($activePreviewPresentation->updated_at?->lessThan(now()->subMinutes($staleInProgressMinutes)) ?? false);
    $badge = $previewBadge($activePreviewPresentation?->status);
@endphp

<div x-show="!isMobile || presentationMobilePanel === 'preview'" x-cloak class="flex-1 flex flex-col min-w-0 bg-stone-50 dark:bg-gray-950 overflow-hidden">
    <div class="relative z-30 min-h-[61px] flex-shrink-0 flex items-center justify-between gap-2 px-3 sm:px-5 border-b border-stone-200/60 bg-white/85 backdrop-blur-sm dark:border-[#1E293B]/70 dark:bg-gray-800/85">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" @click="showPresentationConfigPanel()" class="inline-flex items-center justify-center rounded-lg border border-stone-200 bg-white p-2 text-stone-600 shadow-sm transition hover:bg-stone-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 lg:hidden" aria-label="Kembali ke konfigurasi" title="Kembali">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <div class="inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 py-1 text-[12.5px] font-semibold text-stone-800 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3" />
                </svg>
                <span class="hidden sm:inline">Slides</span>
            </div>
            @if($activePreviewPresentation)
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">{{ $activePreviewPresentation->title }}</p>
                    <p class="text-[11px] text-stone-500 dark:text-gray-400">{{ $templates[$activePreviewPresentation->visual_template] ?? $activePreviewPresentation->visual_template }}</p>
                </div>
                @if(! $isPreviewInProgress)
                    <span class="hidden shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold sm:inline-flex {{ $badge[1] }}">{{ $badge[0] }}</span>
                @endif
            @else
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">Panel Presentasi</p>
                    <p class="text-[11px] text-stone-500 dark:text-gray-400">Editor dan status render tampil di sini</p>
                </div>
            @endif
        </div>

        @if($activePreviewPresentation)
            <div class="flex flex-shrink-0 items-center gap-1.5 sm:gap-2" x-data="presentationDocumentDownloads">
                @if($activePreviewPresentation->isReady())
                    <button type="button" wire:click="retry({{ $activePreviewPresentation->id }})" wire:loading.attr="disabled" wire:target="retry"
                            class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg border border-stone-200 dark:border-gray-700 text-[12px] font-semibold text-stone-600 dark:text-gray-300 hover:bg-stone-100 dark:hover:bg-gray-800 transition-all disabled:opacity-50"
                            aria-label="Regenerate presentasi" title="Regenerate">
                        <span wire:loading wire:target="retry" class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                        <svg wire:loading.remove wire:target="retry" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span class="hidden sm:inline">Regenerate</span>
                    </button>
                    <button type="button"
                            data-download-url="{{ route('presentations.download', $activePreviewPresentation) }}"
                            data-force-save-url="{{ route('presentations.force-save', $activePreviewPresentation) }}"
                            data-download-filename="{{ e(($activePreviewPresentation->title ?: 'presentasi').'.pptx') }}"
                            @click="downloadPresentation($el.dataset.downloadUrl, 'pptx', $el.dataset.downloadFilename, $el.dataset.forceSaveUrl)"
                            :disabled="downloadLoading !== null"
                            class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg border border-stone-200 dark:border-gray-700 text-[12px] font-semibold text-stone-600 dark:text-gray-300 hover:bg-stone-100 dark:hover:bg-gray-800 transition-all disabled:cursor-not-allowed disabled:opacity-60"
                            aria-label="Unduh PPTX" title="Unduh PPTX">
                        <span x-show="downloadLoading === 'pptx'" style="display:none;" class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                        <svg x-show="downloadLoading !== 'pptx'" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="hidden sm:inline">PPTX</span>
                    </button>
                    <button type="button"
                            data-download-url="{{ route('presentations.export.pdf', $activePreviewPresentation) }}"
                            data-force-save-url="{{ route('presentations.force-save', $activePreviewPresentation) }}"
                            data-download-filename="{{ e(($activePreviewPresentation->title ?: 'presentasi').'.pdf') }}"
                            @click="downloadPresentation($el.dataset.downloadUrl, 'pdf', $el.dataset.downloadFilename, $el.dataset.forceSaveUrl)"
                            :disabled="downloadLoading !== null"
                            class="inline-flex items-center gap-1 px-2 py-1.5 sm:px-3 rounded-lg bg-ista-primary text-[12px] font-semibold text-white hover:bg-ista-dark transition-all disabled:cursor-not-allowed disabled:opacity-70"
                            aria-label="Unduh PDF" title="Unduh PDF">
                        <span x-show="downloadLoading === 'pdf'" style="display:none;" class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                        <svg x-show="downloadLoading !== 'pdf'" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="hidden sm:inline">PDF</span>
                    </button>
                    <span x-show="downloadStatus || downloadError" x-cloak x-text="downloadStatus || downloadError" class="hidden max-w-[190px] truncate text-[11px] font-medium text-stone-500 dark:text-gray-400 md:inline" :class="{ 'text-amber-700 dark:text-amber-200': downloadError }" role="status" aria-live="polite"></span>
                @elseif($isPreviewStale)
                    <button type="button" wire:click="retry({{ $activePreviewPresentation->id }})" wire:loading.attr="disabled" wire:target="retry"
                        class="inline-flex items-center gap-1 rounded-lg bg-ista-primary px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-ista-dark disabled:opacity-60">
                        <span wire:loading wire:target="retry" class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                        <span>Kirim ulang</span>
                    </button>
                @endif
            </div>
        @endif
    </div>

    <div class="flex-1 overflow-y-auto">
        @if ($editorConfig)
            <div
                wire:ignore
                wire:key="presentation-editor-{{ $editingPresentationId ?: $activePresentationId }}-{{ md5($editorConfig['document']['key'] ?? '') }}"
                class="h-full min-h-[640px]"
                x-data="{
                    config: @js($editorConfig),
                    apiUrl: @js($onlyOfficeApiUrl),
                    containerId: 'presentation-workspace-editor-{{ md5($editorConfig['document']['key'] ?? '') }}',
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
                                window.presentationOnlyOfficeState = window.presentationOnlyOfficeState || {};
                                window.presentationOnlyOfficeState[documentKey] = {
                                    containerId: this.containerId,
                                    dirty: false,
                                    lastChangeAt: 0,
                                    lastReadyAt: Date.now(),
                                };
                                const existingEvents = this.config.events || {};
                                this.config.events = {
                                    ...existingEvents,
                                    onDocumentReady: (...args) => {
                                        window.presentationOnlyOfficeState[documentKey] = {
                                            ...(window.presentationOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            dirty: false,
                                            lastReadyAt: Date.now(),
                                        };
                                        existingEvents.onDocumentReady?.(...args);
                                    },
                                    onDocumentStateChange: (event) => {
                                        window.presentationOnlyOfficeState[documentKey] = {
                                            ...(window.presentationOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            dirty: Boolean(event?.data),
                                            lastChangeAt: Date.now(),
                                        };
                                        existingEvents.onDocumentStateChange?.(event);
                                    },
                                    onError: (...args) => {
                                        window.presentationOnlyOfficeState[documentKey] = {
                                            ...(window.presentationOnlyOfficeState[documentKey] || {}),
                                            containerId: this.containerId,
                                            lastErrorAt: Date.now(),
                                        };
                                        existingEvents.onError?.(...args);
                                    },
                                };
                                this.editor = new DocsAPI.DocEditor(this.containerId, this.config);
                            } catch (error) {
                                console.error('OnlyOffice Slides editor gagal dimuat', error);
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
                        if (window.presentationOnlyOfficeState?.[documentKey]) {
                            window.presentationOnlyOfficeState[documentKey].destroyedAt = Date.now();
                        }
                        this.editor = null;
                    }
                }"
                x-init="load()"
            >
                <div id="presentation-workspace-editor-{{ md5($editorConfig['document']['key'] ?? '') }}" class="h-full min-h-[640px] w-full"></div>
                <div x-show="editorFailed" x-transition class="flex min-h-[640px] items-center justify-center px-6 text-center" style="display:none;">
                    <div class="max-w-md rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <p class="font-semibold">Editor presentasi belum bisa dimuat.</p>
                        <p class="mt-2 text-sm leading-6">Periksa koneksi ke OnlyOffice, lalu coba muat ulang editor. Unduhan PPTX/PDF tetap tersedia dari tombol di atas bila file sudah siap.</p>
                        <button type="button" @click="load()" class="mt-4 rounded-lg bg-amber-700 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-800">Coba muat ulang editor</button>
                    </div>
                </div>
            </div>
        @elseif($isPreviewInProgress)
            <div class="flex h-full min-h-[520px] items-center justify-center px-6 text-center">
                <div class="max-w-md">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.75)] dark:bg-gray-900">
                        <div class="relative flex h-12 w-12 items-center justify-center rounded-full">
                            <span class="absolute inset-0 rounded-full border-2 border-ista-primary/25 border-t-ista-primary animate-spin"></span>
                            <img src="{{ asset('images/ista/logo.png') }}" alt="" class="h-8 w-8 object-contain" />
                        </div>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">
                        {{ $activePreviewPresentation->status === \App\Models\Presentation::STATUS_PENDING ? 'Dalam antrean render...' : 'Merender presentasi...' }}
                    </h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-stone-500 dark:text-gray-400">
                        <span class="ista-loading-shimmer ista-label-enter" x-text="presentationLoadingPhase"></span>
                    </p>
                    @if($isPreviewStale)
                        <div class="mt-5 rounded-lg border border-ista-primary/25 bg-ista-primary/5 px-3 py-2.5 text-left text-[12px] leading-relaxed text-stone-700 dark:border-ista-primary/35 dark:bg-ista-primary/10 dark:text-gray-200">
                            <p class="font-semibold">Render belum selesai.</p>
                            <p class="mt-0.5 text-stone-500 dark:text-gray-400">Belum ada pembaruan lebih dari {{ $staleInProgressMinutes }} menit.</p>
                            <button type="button" wire:click="retry({{ $activePreviewPresentation->id }})" wire:loading.attr="disabled" wire:target="retry"
                                class="mt-2 inline-flex items-center gap-2 rounded-lg bg-ista-primary px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-ista-dark disabled:opacity-60">
                                <span wire:loading wire:target="retry" class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                                <span>Kirim ulang</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @elseif($activePreviewPresentation?->status === \App\Models\Presentation::STATUS_ERROR)
            <div class="flex h-full min-h-[520px] items-center justify-center px-6 text-center">
                <div class="max-w-md">
                    <div class="mx-auto h-16 w-16 rounded-2xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500 dark:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v4m0 4h.01M4.93 19h14.14a1 1 0 00.86-1.5L12.86 5a1 1 0 00-1.72 0L4.07 17.5A1 1 0 004.93 19z" />
                        </svg>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Render presentasi gagal</h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-stone-500 dark:text-gray-400">
                        {{ $activePreviewPresentation->error_message ?: 'Sistem gagal membuat file PPTX. Coba kirim ulang job render.' }}
                    </p>
                    <button type="button" wire:click="retry({{ $activePreviewPresentation->id }})" wire:loading.attr="disabled" wire:target="retry"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-ista-primary px-4 py-2 text-[12px] font-semibold text-white hover:bg-ista-dark disabled:opacity-60">
                        <span wire:loading wire:target="retry" class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                        <span>Buat ulang presentasi</span>
                    </button>
                </div>
            </div>
        @elseif($activePreviewPresentation && $activePreviewPresentation->isReady())
            <div class="flex h-full min-h-[520px] items-center justify-center px-6 text-center">
                <div class="max-w-md">
                    <div class="mx-auto h-16 w-16 rounded-2xl bg-stone-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-stone-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3" />
                        </svg>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">File siap, editor belum terbuka</h3>
                    <p class="mt-2 text-[13px] text-stone-500 dark:text-gray-400 leading-relaxed">Buka ulang editor Slides untuk presentasi ini atau gunakan tombol unduh di atas.</p>
                    <button type="button" wire:click="editPresentation({{ $activePreviewPresentation->id }})" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-ista-primary px-4 py-2 text-[12px] font-semibold text-white hover:bg-ista-dark">
                        Buka editor Slides
                    </button>
                </div>
            </div>
        @else
            <div wire:loading.flex wire:target="generate" class="h-full min-h-[520px] items-center justify-center px-6 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.75)] dark:bg-gray-900">
                        <div class="relative flex h-12 w-12 items-center justify-center rounded-full">
                            <span class="absolute inset-0 rounded-full border-2 border-ista-primary/25 border-t-ista-primary animate-spin"></span>
                            <img src="{{ asset('images/ista/logo.png') }}" alt="" class="h-8 w-8 object-contain" />
                        </div>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Memulai render presentasi...</h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-stone-500 dark:text-gray-400">
                        <span class="ista-loading-shimmer ista-label-enter" x-text="presentationLoadingPhase"></span>
                    </p>
                </div>
            </div>

            <div wire:loading.remove wire:target="generate" class="flex h-full min-h-[520px] items-center justify-center px-6 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto h-16 w-16 rounded-2xl bg-stone-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-stone-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3" />
                        </svg>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Belum ada presentasi aktif</h3>
                    <p class="mt-2 text-[13px] text-stone-500 dark:text-gray-400 leading-relaxed">Pilih deck dari riwayat atau buat presentasi baru. Status render dan editor OnlyOffice Slides akan tampil di panel ini.</p>
                </div>
            </div>
        @endif
    </div>
</div>
