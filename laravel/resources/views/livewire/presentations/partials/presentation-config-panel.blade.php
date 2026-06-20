<div
    x-show="!isMobile || presentationMobilePanel === 'config'"
    x-cloak
    class="flex flex-col w-full lg:w-[460px] xl:w-[560px] flex-shrink-0 border-r border-stone-200/70 dark:border-[#1E293B] bg-transparent overflow-hidden"
>
    <div class="h-[61px] flex-shrink-0 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
        <div class="flex min-w-0 items-center gap-2 justify-self-start">
            <button type="button" @click="showPresentationSidebar = !showPresentationSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0" aria-label="Toggle presentation sidebar">
                <img src="{{ asset('images/icons/collapse-left-light.svg') }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showPresentationSidebar ? 'rotate-0' : 'rotate-180'" />
                <img src="{{ asset('images/icons/collapse-left-dark.svg') }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showPresentationSidebar ? 'rotate-0' : 'rotate-180'" />
            </button>
            <button type="button"
                    wire:click="startNewPresentation"
                    class="group flex min-w-0 items-center"
                    aria-label="Buat presentasi baru"
                    title="Buat presentasi baru">
                <span class="ista-brand-title text-xl text-ista-primary not-italic transition-transform duration-300 group-hover:scale-105">ISTA <span class="font-light italic text-ista-gold">AI</span></span>
            </button>
        </div>

        <div class="justify-self-center">
            @include('livewire.chat.partials.chat-memo-tab-toggle')
        </div>

        <div class="flex shrink-0 items-center gap-2 justify-self-end">
            <button type="button" @click="darkMode = !darkMode" :aria-pressed="darkMode ? 'true' : 'false'" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors" aria-label="Toggle dark mode">
                <svg x-show="darkMode === false" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#64748B]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                </svg>
                <svg x-show="darkMode === true" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                </svg>
            </button>

            <button type="button" @click="showPresentationPreviewPanel()" title="Panel presentasi" aria-label="Buka panel presentasi" class="inline-flex items-center gap-1.5 rounded-[10px] border border-stone-200/70 px-2.5 py-2 text-[11px] font-semibold text-stone-600 transition hover:bg-[#F1F5F9] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3" />
                </svg>
                <span>Hasil</span>
            </button>
        </div>
    </div>

    <div class="lg:hidden border-b border-amber-200/70 bg-amber-50/[0.92] px-4 py-3 text-[12.5px] leading-relaxed text-amber-900 shadow-sm backdrop-blur-sm dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-100" role="note">
        Mode presentasi di mobile tersedia untuk konfigurasi cepat. Gunakan tombol Hasil untuk melihat status render atau editor Slides.
    </div>

    <div class="border-b border-stone-200/70 px-4 py-3 dark:border-[#1E293B]/70">
        <div class="grid grid-cols-2 gap-1 rounded-lg border border-stone-200/70 bg-white/80 p-1 dark:border-gray-700 dark:bg-gray-900/70">
            <button type="button" wire:click="setSubMode('create')"
                class="rounded-md px-3 py-2 text-[12px] font-bold transition {{ $subMode === 'create' ? 'bg-ista-primary text-white shadow-sm' : 'text-stone-500 hover:bg-stone-100 dark:text-gray-400 dark:hover:bg-gray-800' }}">
                Buat PPT ISTA
            </button>
            <button type="button" wire:click="setSubMode('prompy')"
                class="rounded-md px-3 py-2 text-[12px] font-bold transition {{ $subMode === 'prompy' ? 'bg-ista-primary text-white shadow-sm' : 'text-stone-500 hover:bg-stone-100 dark:text-gray-400 dark:hover:bg-gray-800' }}">
                Prompy Studio
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto bg-transparent px-4 py-4 space-y-4" x-ref="presentationConfigBox" id="presentation-config-box">
        @error('rate_limit')
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-700 dark:border-red-800/50 dark:bg-red-900/20 dark:text-red-300">{{ $message }}</div>
        @enderror
        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-left dark:border-rose-900/60 dark:bg-rose-950/30">
                <p class="text-[12px] font-semibold text-rose-700 dark:text-rose-300">Belum bisa membuat presentasi.</p>
                <p class="mt-0.5 text-[11.5px] leading-relaxed text-rose-600 dark:text-rose-300">
                    {{ $errors->first() }}
                </p>
            </div>
        @endif

        <form id="presentation-config-form" wire:submit.prevent="generate" class="chat-form memo-config-panel">
            <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="mt-1 text-[15px] font-bold text-stone-900 dark:text-gray-100">Konfigurasi Presentasi</h2>
                        <p class="mt-1 max-w-[26rem] text-[12px] leading-relaxed text-stone-500 dark:text-gray-400">Atur isi inti, dokumen sumber, dan gaya visual deck.</p>
                    </div>
                    @if($activePresentationId)
                        <span class="rounded-full bg-ista-primary/10 px-2.5 py-1 text-[10.5px] font-bold text-ista-primary dark:bg-amber-300/10 dark:text-amber-200">Ada presentasi aktif</span>
                    @endif
                </div>
            </div>

            <div class="memo-config-section bg-stone-50/65 dark:bg-gray-950/20">
                <div>
                    <label class="memo-config-label">Judul presentasi <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="title" maxlength="160" placeholder="Mis. Paparan Evaluasi Triwulan II" class="memo-config-control" />
                    @error('title') <p class="memo-config-error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="memo-config-label">Template visual</label>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($templates as $key => $label)
                            <button type="button" wire:click="selectTemplate('{{ $key }}')"
                                class="min-h-10 rounded-md border px-3 py-2 text-left text-[12px] font-semibold transition-all {{ $visualTemplate === $key ? 'border-ista-primary bg-ista-primary/5 text-ista-primary dark:bg-gray-800' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @error('visualTemplate') <p class="memo-config-error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="memo-config-label">Audiens / tujuan</label>
                        <input type="text" wire:model="audience" maxlength="200" placeholder="Mis. Kepala Istana" class="memo-config-control" />
                    </div>
                    <div>
                        <label class="memo-config-label">Jumlah slide</label>
                        <input type="number" wire:model="slideCount" min="{{ $slideCountMin }}" max="{{ $slideCountMax }}" class="memo-config-control" />
                        @error('slideCount') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="memo-config-label">Header</label>
                        <input type="text" wire:model="header" maxlength="200" class="memo-config-control" />
                    </div>
                    <div>
                        <label class="memo-config-label">Footer</label>
                        <input type="text" wire:model="footer" maxlength="200" class="memo-config-control" />
                    </div>
                    <div>
                        <label class="memo-config-label">Penyusun</label>
                        <input type="text" wire:model="presenter" maxlength="200" class="memo-config-control" />
                    </div>
                    <div>
                        <label class="memo-config-label">Unit</label>
                        <input type="text" wire:model="unit" maxlength="200" class="memo-config-control" />
                    </div>
                </div>

                <div class="mt-3">
                    <label class="memo-config-label">Arahan tambahan / fokus pembahasan</label>
                    <textarea wire:model="additionalInstruction" rows="3" maxlength="2000" placeholder="Mis. Tonjolkan capaian dan risiko, ringkas untuk Kepala Istana." class="memo-config-textarea"></textarea>
                    @error('additionalInstruction') <p class="memo-config-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="memo-config-section">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[11.5px] font-bold text-stone-700 dark:text-gray-200">Dokumen sumber</p>
                        <p class="mt-0.5 text-[11.5px] text-stone-500 dark:text-gray-500">Opsional, hanya dokumen ready milik Anda.</p>
                    </div>
                    <span class="text-[10.5px] font-semibold text-stone-400 dark:text-gray-500">{{ count($selectedDocuments) }} dipilih</span>
                </div>
                @if($availableDocuments->isEmpty())
                    <p class="rounded-lg border border-dashed border-stone-200/70 px-3 py-3 text-[12px] text-stone-400 dark:border-gray-800 dark:text-gray-500">Belum ada dokumen ready. Unggah dokumen lewat tab Chat terlebih dahulu.</p>
                @else
                    <div class="max-h-44 space-y-1 overflow-y-auto pr-1">
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
        </form>
    </div>

    <div class="chat-composer-safe flex-shrink-0 px-4 pt-2 bg-transparent w-full">
        <div class="rounded-lg border border-stone-200 bg-white p-2 shadow-[0_-10px_30px_-24px_rgba(28,25,23,0.45)] dark:border-gray-800 dark:bg-gray-900">
            <button type="submit"
                    form="presentation-config-form"
                    wire:loading.attr="disabled"
                    wire:target="generate"
                    @click="startPresentationLoadingPhase(); showPresentationPreviewPanel()"
                    class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-ista-primary px-4 text-[13px] font-semibold text-white shadow-sm transition hover:bg-ista-dark active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50">
                <span wire:loading.remove wire:target="generate">Buat Presentasi</span>
                <span wire:loading.inline-flex wire:target="generate" class="items-center gap-2">
                    <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                    <span>Memulai render...</span>
                </span>
            </button>
        </div>
        <div class="text-center mt-3 text-[11px] text-[#94A3B8] dark:text-[#64748B]">
            Periksa ulang deck sebelum dipakai untuk paparan resmi.
        </div>
    </div>
</div>
