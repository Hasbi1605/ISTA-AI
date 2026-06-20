<div class="flex w-full h-full flex-col overflow-hidden bg-transparent">
    @if($hasInProgress)
        {{-- Polling status presentasi yang sedang diproses --}}
        <div wire:poll.5s class="hidden" aria-hidden="true"></div>
    @endif

    {{-- Header konsisten dengan shell Chat/Memo --}}
    <div class="h-[61px] flex-shrink-0 flex items-center justify-between px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
        <div class="flex items-center gap-2 sm:gap-4">
            <div class="ista-brand-title text-xl text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></div>
            <span class="text-[13px] font-semibold text-stone-500 dark:text-gray-400">Presentasi</span>
        </div>

        {{-- Segmented control sub-mode --}}
        <div class="inline-flex items-center rounded-full border border-stone-200/80 bg-white/80 p-1 shadow-sm dark:border-gray-700 dark:bg-gray-800/80">
            <button type="button" wire:click="setSubMode('create')"
                class="rounded-full px-3 py-1.5 text-[12px] font-semibold transition-all {{ $subMode === 'create' ? 'bg-ista-primary text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 dark:text-gray-400' }}">
                Buat PPT ISTA
            </button>
            <button type="button" wire:click="setSubMode('prompy')"
                class="rounded-full px-3 py-1.5 text-[12px] font-semibold transition-all {{ $subMode === 'prompy' ? 'bg-ista-primary text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 dark:text-gray-400' }}">
                Prompy Studio
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
        @if($statusMessage)
            <div class="mb-4 rounded-xl border border-ista-primary/20 bg-ista-primary/5 px-4 py-3 text-[13px] text-ista-primary dark:border-ista-gold/20 dark:bg-gray-800/60 dark:text-amber-200">
                {{ $statusMessage }}
            </div>
        @endif
        @error('rate_limit')
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 dark:border-red-800/50 dark:bg-red-900/20 dark:text-red-300">{{ $message }}</div>
        @enderror

        @if($editorConfig)
            {{-- ===== EDITOR ONLYOFFICE SLIDES (#226) ===== --}}
            <div class="mb-4 flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-stone-800 dark:text-gray-100">Edit Presentasi</h3>
                <button type="button" wire:click="closeEditor"
                    class="inline-flex items-center gap-1 rounded-lg border border-stone-200 px-3 py-1.5 text-[12px] font-semibold text-stone-600 hover:bg-stone-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                    Tutup editor
                </button>
            </div>
            <div
                wire:ignore
                wire:key="presentation-editor-{{ $editingPresentationId }}-{{ md5($editorConfig['document']['key'] ?? '') }}"
                class="h-[70vh] min-h-[560px] overflow-hidden rounded-2xl border border-stone-200/80 dark:border-gray-700"
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
                <div id="presentation-workspace-editor-{{ md5($editorConfig['document']['key'] ?? '') }}" class="h-full w-full"></div>
                <div x-show="editorFailed" x-transition class="flex h-full items-center justify-center px-6 text-center" style="display:none;">
                    <div class="max-w-md rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <p class="font-semibold">Editor presentasi belum bisa dimuat.</p>
                        <p class="mt-2 text-sm leading-6">Periksa koneksi ke OnlyOffice, lalu coba muat ulang editor. Anda tetap bisa mengunduh PPTX/PDF dari riwayat.</p>
                        <button type="button" @click="load()" class="mt-4 rounded-lg bg-amber-700 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-800">Coba muat ulang editor</button>
                    </div>
                </div>
            </div>
        @elseif($subMode === 'prompy')
            {{-- ===== PROMPY STUDIO (#263) — komponen terpisah, state independen ===== --}}
            <livewire:presentations.prompy-studio />
        @else
            {{-- ===== BUAT PPT ISTA ===== --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
                {{-- Form konfigurasi --}}
                <div class="lg:col-span-3 space-y-5">
                    <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
                        <h3 class="mb-4 text-sm font-bold text-stone-800 dark:text-gray-100">Konfigurasi Presentasi</h3>

                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Judul presentasi <span class="text-red-500">*</span></label>
                                <input type="text" wire:model="title" maxlength="160" placeholder="Mis. Paparan Evaluasi Triwulan II"
                                    class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                @error('title') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Template visual</label>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    @foreach($templates as $key => $label)
                                        <button type="button" wire:click="selectTemplate('{{ $key }}')"
                                            class="rounded-xl border px-3 py-2 text-left text-[12px] font-semibold transition-all {{ $visualTemplate === $key ? 'border-ista-primary bg-ista-primary/5 text-ista-primary dark:bg-gray-800' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300' }}">
                                            {{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                                @error('visualTemplate') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Audiens / tujuan</label>
                                    <input type="text" wire:model="audience" maxlength="200" placeholder="Mis. Kepala Istana"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Jumlah slide</label>
                                    <input type="number" wire:model="slideCount" min="{{ $slideCountMin }}" max="{{ $slideCountMax }}"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                    @error('slideCount') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Header</label>
                                    <input type="text" wire:model="header" maxlength="200"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Footer</label>
                                    <input type="text" wire:model="footer" maxlength="200"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Penyusun</label>
                                    <input type="text" wire:model="presenter" maxlength="200"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Unit</label>
                                    <input type="text" wire:model="unit" maxlength="200"
                                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Arahan tambahan / fokus pembahasan</label>
                                <textarea wire:model="additionalInstruction" rows="3" maxlength="2000" placeholder="Mis. Tonjolkan capaian dan risiko, ringkas untuk Kepala Istana."
                                    class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                @error('additionalInstruction') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Pemilih dokumen ready --}}
                    <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
                        <h3 class="mb-1 text-sm font-bold text-stone-800 dark:text-gray-100">Dokumen sumber (opsional)</h3>
                        <p class="mb-3 text-[11px] text-stone-500 dark:text-gray-400">Hanya dokumen Anda yang sudah siap (ready) yang bisa dipilih.</p>
                        @if($availableDocuments->isEmpty())
                            <p class="text-[12px] text-stone-400 dark:text-gray-500">Belum ada dokumen ready. Unggah dokumen lewat tab Chat terlebih dahulu.</p>
                        @else
                            <div class="max-h-40 space-y-1.5 overflow-y-auto pr-1">
                                @foreach($availableDocuments as $doc)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-stone-50 dark:hover:bg-gray-800">
                                        <input type="checkbox" wire:click="toggleDocument({{ $doc->id }})"
                                            @checked(in_array((int) $doc->id, array_map('intval', $selectedDocuments), true))
                                            class="h-4 w-4 rounded border-stone-300 text-ista-primary focus:ring-ista-primary/30" />
                                        <span class="truncate text-[12px] text-stone-700 dark:text-gray-300">{{ $doc->original_name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-ista-primary px-4 py-2.5 text-[13px] font-bold text-white shadow-sm transition-all hover:bg-ista-primary/90 disabled:opacity-60 sm:w-auto">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3" />
                        </svg>
                        <span wire:loading.remove wire:target="generate">Buat Presentasi</span>
                        <span wire:loading wire:target="generate">Memproses...</span>
                    </button>
                </div>

                {{-- Riwayat & status --}}
                <div class="lg:col-span-2">
                    <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
                        <h3 class="mb-3 text-sm font-bold text-stone-800 dark:text-gray-100">Riwayat Presentasi</h3>
                        @if($presentations->isEmpty())
                            <p class="text-[12px] text-stone-400 dark:text-gray-500">Belum ada presentasi. Buat presentasi pertama Anda.</p>
                        @else
                            <div class="space-y-2.5">
                                @foreach($presentations as $p)
                                    <div class="rounded-xl border border-stone-200/70 p-3 dark:border-gray-700 {{ (int) $activePresentationId === (int) $p->id ? 'ring-1 ring-ista-primary/30' : '' }}" wire:key="presentation-{{ $p->id }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">{{ $p->title }}</p>
                                                <p class="text-[11px] text-stone-400 dark:text-gray-500">{{ $templates[$p->visual_template] ?? $p->visual_template }}</p>
                                            </div>
                                            @php
                                                $badge = match($p->status) {
                                                    \App\Models\Presentation::STATUS_READY => ['Siap', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
                                                    \App\Models\Presentation::STATUS_PROCESSING => ['Diproses', 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
                                                    \App\Models\Presentation::STATUS_ERROR => ['Gagal', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
                                                    default => ['Menunggu', 'bg-stone-100 text-stone-600 dark:bg-gray-700 dark:text-gray-300'],
                                                };
                                            @endphp
                                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $badge[1] }}">{{ $badge[0] }}</span>
                                        </div>

                                        @if($p->status === \App\Models\Presentation::STATUS_ERROR && $p->error_message)
                                            <p class="mt-1.5 text-[11px] text-red-500">{{ $p->error_message }}</p>
                                        @endif

                                        <div class="mt-2.5 flex flex-wrap items-center gap-2"
                                            x-data="presentationDocumentDownloads"
                                        >
                                            @if($p->isReady())
                                                <button type="button" wire:click="editPresentation({{ $p->id }})"
                                                    class="inline-flex items-center gap-1 rounded-lg bg-ista-primary px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-ista-primary/90">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                    </svg>
                                                    Edit Presentasi
                                                </button>
                                                <button type="button"
                                                    data-download-url="{{ route('presentations.download', $p) }}"
                                                    data-force-save-url="{{ route('presentations.force-save', $p) }}"
                                                    data-download-filename="{{ e(($p->title ?: 'presentasi').'.pptx') }}"
                                                    @click="downloadPresentation($el.dataset.downloadUrl, 'pptx', $el.dataset.downloadFilename, $el.dataset.forceSaveUrl)"
                                                    :disabled="downloadLoading !== null"
                                                    class="inline-flex items-center gap-1 rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15 disabled:opacity-60">
                                                    PPTX
                                                </button>
                                                <button type="button"
                                                    data-download-url="{{ route('presentations.export.pdf', $p) }}"
                                                    data-force-save-url="{{ route('presentations.force-save', $p) }}"
                                                    data-download-filename="{{ e(($p->title ?: 'presentasi').'.pdf') }}"
                                                    @click="downloadPresentation($el.dataset.downloadUrl, 'pdf', $el.dataset.downloadFilename, $el.dataset.forceSaveUrl)"
                                                    :disabled="downloadLoading !== null"
                                                    class="inline-flex items-center gap-1 rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15 disabled:opacity-60">
                                                    PDF
                                                </button>
                                            @endif
                                            @if(in_array($p->status, [\App\Models\Presentation::STATUS_ERROR, \App\Models\Presentation::STATUS_READY], true))
                                                <button type="button" wire:click="retry({{ $p->id }})"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-stone-200 px-2.5 py-1 text-[11px] font-semibold text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300">
                                                    Buat ulang
                                                </button>
                                            @endif
                                            <button type="button" wire:click="deletePresentation({{ $p->id }})"
                                                wire:confirm="Hapus presentasi ini?"
                                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-semibold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                Hapus
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
