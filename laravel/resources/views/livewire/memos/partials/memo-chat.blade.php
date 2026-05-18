{{-- Memo AI Chat Panel (Center Column) --}}
<div
    x-show="!isMobile || memoMobilePanel === 'chat'"
    x-cloak
    class="flex flex-col w-full lg:w-[460px] xl:w-[560px] flex-shrink-0 border-r border-stone-200/70 dark:border-[#1E293B] bg-transparent overflow-hidden"
    x-on:memo-configuration-invalid.window="$nextTick(() => {
        const error = $refs.memoChatBox?.querySelector('.memo-config-error');
        if (! error || ! $refs.memoChatBox) return;
        $refs.memoChatBox.scrollTo({ top: Math.max(0, error.offsetTop - 96), behavior: 'smooth' });
    })"
>

    {{-- Header with sidebar toggle, brand, tab toggle, and theme toggle --}}
    <div class="h-[61px] flex-shrink-0 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
        <div class="flex min-w-0 items-center gap-2 justify-self-start">
            <button type="button" @click="showMemoSidebar = !showMemoSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0" aria-label="Toggle memo sidebar">
                <img src="{{ asset('images/icons/collapse-left-light.svg') }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showMemoSidebar ? 'rotate-0' : 'rotate-180'" />
                <img src="{{ asset('images/icons/collapse-left-dark.svg') }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showMemoSidebar ? 'rotate-0' : 'rotate-180'" />
            </button>
            <button type="button"
                    wire:click="startNewMemo"
                    wire:loading.attr="disabled"
                    wire:target="startNewMemo"
                    class="group flex min-w-0 items-center"
                    aria-label="Buat memo baru"
                    title="Buat memo baru">
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

            <button type="button" @click="showMemoDocumentPanel()" title="Dokumen" aria-label="Buka panel dokumen memo" class="inline-flex items-center gap-1.5 rounded-[10px] border border-stone-200/70 px-2.5 py-2 text-[11px] font-semibold text-stone-600 transition hover:bg-[#F1F5F9] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414A1 1 0 0 1 19 9.414V19a2 2 0 0 1-2 2Z" />
                </svg>
                <span>Dokumen</span>
            </button>
        </div>
    </div>

    <div class="lg:hidden border-b border-amber-200/70 bg-amber-50/[0.92] px-4 py-3 text-[12.5px] leading-relaxed text-amber-900 shadow-sm backdrop-blur-sm dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-100" role="note">
        Mode memo di mobile tersedia untuk review cepat. Gunakan tombol Dokumen untuk melihat hasil, atau lanjutkan penyuntingan detail di desktop bila membutuhkan ruang kerja penuh.
    </div>

    {{-- Dynamic Configuration / Chat Area --}}
    <div class="flex-1 overflow-y-auto bg-transparent px-4 py-4 space-y-4" x-ref="memoChatBox" id="memo-chat-box">
        @if ($activeMemoId)
            <div class="rounded-lg border border-stone-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Memo aktif</p>
                        <p class="mt-1 truncate text-[13.5px] font-semibold text-stone-800 dark:text-gray-100">{{ $title ?: 'Tanpa hal' }}</p>
                        <p class="mt-1 text-[11.5px] text-stone-500 dark:text-gray-400">{{ $memoNumber ?: 'Nomor belum diisi' }} · {{ $memoDate ?: 'Tanggal belum diisi' }}</p>
                    </div>
                    <button type="button"
                            wire:click="$toggle('showMemoConfiguration')"
                            class="shrink-0 rounded-lg border border-stone-200 px-2.5 py-1.5 text-[11px] font-semibold text-stone-600 transition hover:bg-stone-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        {{ $showMemoConfiguration ? 'Tutup konfigurasi' : 'Edit konfigurasi' }}
                    </button>
                </div>
                @if (($activeMemoVersions ?? collect())->count() > 1)
                    <div class="mt-3 flex items-center gap-2 border-t border-stone-100 pt-3 dark:border-gray-800">
                        <label for="memo-version-select" class="shrink-0 text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Versi memo</label>
                        <select id="memo-version-select"
                                x-on:change.stop.prevent="switchMemoVersion($wire, $event.target.value)"
                                class="min-w-0 flex-1 rounded-md border border-stone-200 bg-white px-2.5 py-1.5 text-[12px] font-semibold text-stone-700 shadow-sm outline-none focus:border-ista-primary focus:outline-none focus:ring-1 focus:ring-ista-primary focus-visible:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @foreach ($activeMemoVersions as $version)
                                <option value="{{ $version->id }}" @selected((int) $activeMemoVersionId === (int) $version->id)>
                                    Versi {{ $version->version_number }} · {{ $version->created_at?->format('H:i') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        @endif

        @if (! $activeMemoId || $showMemoConfiguration)
            <form id="memo-config-form" @submit.prevent="submitMemoConfiguration($wire)" class="chat-form memo-config-panel">
                <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="mt-1 text-[15px] font-bold text-stone-900 dark:text-gray-100">Konfigurasi Memo</h2>
                            <p class="mt-1 max-w-[26rem] text-[12px] leading-relaxed text-stone-500 dark:text-gray-400">Isi data inti untuk membuat draft memorandum.</p>
                        </div>
                    </div>
                </div>

                <div class="memo-config-section bg-stone-50/65 dark:bg-gray-950/20">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-[11.5px] font-bold text-stone-700 dark:text-gray-200">Identitas memo</p>
                        <p class="text-[11px] font-medium text-stone-400 dark:text-gray-500">Memorandum</p>
                    </div>
                    <div>
                        <label class="memo-config-label">Format dokumen</label>
                        <select wire:model="memoPageSize" class="memo-config-control">
                            @foreach ($memoPageSizes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('memoPageSize') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Nomor Memo</label>
                        <input type="text" wire:model="memoNumber" placeholder="Contoh: M-01/UNIT/05/2026"
                               class="memo-config-control">
                        @error('memoNumber') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Yth.</label>
                        <input type="text" wire:model="memoRecipient" placeholder="Contoh: Pejabat atau unit yang dituju"
                               class="memo-config-control">
                        @error('memoRecipient') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Dari</label>
                        <input type="text" wire:model="memoSender" placeholder="Contoh: Kepala Istana Kepresidenan Yogyakarta"
                               class="memo-config-control">
                        @error('memoSender') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_150px]">
                        <div>
                            <label class="memo-config-label">Hal</label>
                            <input type="text" wire:model="title" placeholder="Contoh: Penyampaian, permohonan, atau koordinasi ..."
                                   class="memo-config-control">
                            @error('title') <p class="memo-config-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="memo-config-label">Tanggal</label>
                            <input type="text" wire:model="memoDate"
                                   class="memo-config-control">
                            @error('memoDate') <p class="memo-config-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="memo-config-section">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-[11.5px] font-bold text-stone-700 dark:text-gray-200">Isi memo</p>
                    </div>
                    <div>
                        <label class="memo-config-label">Dasar / Konteks</label>
                        <textarea wire:model="memoBasis" rows="2" placeholder="Contoh: Menindaklanjuti arahan, rapat, surat, atau kebutuhan ..."
                                  class="memo-config-textarea"></textarea>
                        @error('memoBasis') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Isi / Poin Wajib</label>
                        <textarea wire:model="memoContent" rows="3" placeholder="Tuliskan tujuan, data penting, batas waktu, atau poin yang wajib masuk."
                                  class="memo-config-textarea"></textarea>
                        @error('memoContent') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Penutup</label>
                        <input type="text" wire:model="memoClosing" placeholder="Opsional: tentukan kalimat penutup manual"
                               class="memo-config-control">
                        @error('memoClosing') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="memo-config-section bg-stone-50/50 dark:bg-gray-950/20">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-[11.5px] font-bold text-stone-700 dark:text-gray-200">Distribusi dan arahan</p>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="memo-config-label">Penandatangan</label>
                            <input type="text" wire:model="memoSignatory" placeholder="Contoh: Nama pejabat penandatangan"
                                   class="memo-config-control">
                            @error('memoSignatory') <p class="memo-config-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="memo-config-label">Tembusan</label>
                            <textarea wire:model="memoCarbonCopy" rows="2" placeholder="Opsional: satu tembusan per baris"
                                      class="memo-config-textarea"></textarea>
                            @error('memoCarbonCopy') <p class="memo-config-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Arahan Tambahan</label>
                        <textarea wire:model="memoAdditionalInstruction" rows="2" placeholder="Opsional: atur nada, panjang, format poin, atau batasan khusus."
                                  class="memo-config-textarea"></textarea>
                        @error('memoAdditionalInstruction') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($activeMemoId)
                        <div class="mt-3">
                            <label class="memo-config-label">Mode generate ulang</label>
                            <select wire:model="memoRegenerateMode" class="memo-config-control">
                                <option value="preserve_document">Pertahankan isi dokumen terbaru</option>
                                <option value="form_only">Gunakan isi form saja</option>
                            </select>
                            @error('memoRegenerateMode') <p class="memo-config-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            </form>
        @endif

        @if (! $showMemoConfiguration)
            @foreach ($memoChatMessages as $index => $msg)
                @php
                    $isUserMessage = $msg['role'] === 'user';
                @endphp

                <div wire:key="memo-msg-{{ $index }}" class="flex {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                    <div class="w-full flex items-start gap-2.5 {{ $isUserMessage ? 'flex-row-reverse' : '' }}">
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center {{ $isUserMessage ? 'bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black' : 'bg-white border border-stone-200 shadow-sm p-1' }}">
                            @if ($isUserMessage)
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            @else
                                <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
                            @endif
                        </div>

                        <div class="flex max-w-[82%] flex-col gap-1 {{ $isUserMessage ? 'items-end text-right' : 'items-start text-left' }}">
                            <div class="flex items-center gap-2 mb-1 {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                                <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">{{ $isUserMessage ? 'Anda' : 'ISTA AI' }}</span>
                                <span class="text-[10px] text-gray-400 dark:text-[#64748B]">{{ $msg['timestamp'] }}</span>
                            </div>

                            <div class="{{ $isUserMessage
                                ? 'bg-ista-primary text-white rounded-lg rounded-br-sm px-4 py-3'
                                : 'bg-white/95 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/80 dark:border-gray-800 text-stone-700 dark:text-gray-100 rounded-lg rounded-bl-sm px-4 py-3 shadow-sm' }}">
                                <p class="text-[14px] leading-relaxed whitespace-pre-wrap">{{ $msg['content'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif

        @if (! $showMemoConfiguration)
            <template x-if="memoRevisionText">
                <div class="flex justify-end">
                    <div class="w-full flex items-start gap-2.5 flex-row-reverse">
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                        <div class="flex max-w-[82%] flex-col gap-1 items-end text-right">
                            <div class="flex items-center gap-2 mb-1 justify-end">
                                <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">Anda</span>
                            </div>
                            <div class="bg-ista-primary text-white rounded-lg rounded-br-sm px-4 py-3">
                                <p class="text-[14px] leading-relaxed whitespace-pre-wrap" x-text="memoRevisionText"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex justify-start" x-show="memoRevisionLoading || $wire.isGenerating" x-cloak>
                <div class="w-full flex items-start gap-2.5">
                    <div class="shrink-0 h-8 w-8 rounded-full bg-white border border-stone-200 shadow-sm p-1 flex items-center justify-center">
                        <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
                    </div>
                    <div class="flex max-w-[82%] flex-col gap-1 items-start text-left">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">ISTA AI</span>
                        </div>
                        <div class="inline-flex w-auto items-center gap-2.5 rounded-xl rounded-bl-md border border-stone-200/60 bg-white/80 px-4 py-3 backdrop-blur-sm dark:border-gray-800 dark:bg-gray-800" role="status" aria-live="polite">
                            <span class="relative inline-flex h-4 w-4 items-center justify-center">
                                <span class="absolute inset-0 animate-spin" style="animation-duration: 2.8s; animation-timing-function: linear;">
                                    <span class="absolute left-1/2 top-0 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-gray-400/90 dark:bg-[#64748B]"></span>
                                    <span class="absolute left-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-400/75 dark:bg-[#64748B]/90"></span>
                                    <span class="absolute right-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-400/60 dark:bg-[#64748B]/80"></span>
                                </span>
                                <span class="absolute left-1/2 top-0 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-gray-500/90 dark:bg-[#94A3B8] animate-pulse" style="animation-duration: 1.3s;"></span>
                                <span class="absolute left-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-500/80 dark:bg-[#94A3B8]/90 animate-pulse" style="animation-duration: 1.5s; animation-delay: 0.12s;"></span>
                                <span class="absolute right-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-500/70 dark:bg-[#94A3B8]/80 animate-pulse" style="animation-duration: 1.7s; animation-delay: 0.24s;"></span>
                            </span>
                            <span class="ista-loading-shimmer ista-label-enter text-[12px] font-medium whitespace-nowrap"
                                x-text="memoLoadingPhase"
                                x-effect="
                                    memoLoadingPhaseKey;
                                    $el.classList.remove('ista-label-enter');
                                    void $el.offsetWidth;
                                    $el.classList.add('ista-label-enter');
                                "
                            ></span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Revision Chat Input --}}
    <div class="chat-composer-safe flex-shrink-0 px-4 pt-2 bg-transparent w-full">
        @if (! $activeMemoId || $showMemoConfiguration)
            <div class="rounded-lg border border-stone-200 bg-white p-2 shadow-[0_-10px_30px_-24px_rgba(28,25,23,0.45)] dark:border-gray-800 dark:bg-gray-900">
                @if ($errors->any())
                    <div class="mb-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-left dark:border-rose-900/60 dark:bg-rose-950/30">
                        <p class="text-[12px] font-semibold text-rose-700 dark:text-rose-300">Belum bisa generate memo.</p>
                        <p class="mt-0.5 text-[11.5px] leading-relaxed text-rose-600 dark:text-rose-300">
                            {{ $errors->first() }}
                        </p>
                    </div>
                @endif
                <div x-show="memoSyncError" x-cloak class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-left dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-[12px] font-semibold text-amber-800 dark:text-amber-200" x-text="memoSyncError"></p>
                </div>
                <button type="submit"
                        form="memo-config-form"
                        wire:loading.attr="disabled"
                        wire:target="generateConfiguredMemo,generateFromChat"
                        :disabled="memoSyncLoading || $wire.isGenerating"
                        class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-ista-primary px-4 text-[13px] font-semibold text-white shadow-sm transition hover:bg-ista-dark active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-show="memoSyncLoading" x-cloak class="inline-flex items-center gap-2">
                        <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                        <span>Menyimpan perubahan...</span>
                    </span>
                    <span x-show="!memoSyncLoading" wire:loading.remove wire:target="generateConfiguredMemo,generateFromChat">{{ $activeMemoId ? 'Buat ulang dari konfigurasi' : 'Buat memo' }}</span>
                    <span x-show="!memoSyncLoading" wire:loading.inline-flex wire:target="generateConfiguredMemo,generateFromChat" class="items-center gap-2">
                        <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                        <span>Memproses...</span>
                    </span>
                </button>
            </div>
        @elseif ($activeMemoId)
            <form @submit.prevent="submitMemoRevision($wire, $refs.memoInput)" class="chat-form relative rounded-xl shadow-sm bg-white dark:bg-gray-800 border border-stone-200/60 dark:border-gray-700 transition-colors">
                <div class="px-3 pb-3 pt-3 w-full">
                    <div x-show="memoSyncError" x-cloak class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-left dark:border-amber-900/60 dark:bg-amber-950/30">
                        <p class="text-[12px] font-semibold text-amber-800 dark:text-amber-200" x-text="memoSyncError"></p>
                    </div>
                    <textarea
                        wire:model="memoPrompt"
                        x-ref="memoInput"
                        @keydown.enter="if(!$event.shiftKey) { $event.preventDefault(); submitMemoRevision($wire, $refs.memoInput); }"
                        placeholder="Tulis revisi untuk memo ini..."
                        aria-label="Tulis revisi untuk memo ini"
                        rows="1"
                        class="chat-input w-full max-h-[120px] min-h-[44px] bg-transparent border-none focus:ring-0 focus:outline-none focus:border-transparent focus-visible:ring-0 focus-visible:outline-none resize-none text-[14px] text-stone-800 dark:text-[#F8FAFC] placeholder-[#94A3B8] dark:placeholder-[#64748B] px-2 py-[10px] hover:bg-transparent focus:bg-transparent"
                        style="outline: none !important; box-shadow: none !important;"
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                    ></textarea>

                    <div class="mt-2 flex items-center justify-end">
                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="sendMemoChat,generateRevisionFromChat"
                                :disabled="memoRevisionLoading || $wire.isGenerating"
                                class="bg-ista-primary hover:bg-ista-dark dark:bg-ista-primary dark:hover:bg-ista-dark disabled:opacity-50 disabled:cursor-not-allowed rounded-full transition-all duration-300 h-[32px] w-[32px] flex items-center justify-center group"
                                aria-label="Kirim revisi memo">
                            <img src="{{ asset('images/icons/send-light.svg') }}" alt="" class="h-[17px] w-[17px] dark:hidden brightness-0 invert" />
                            <img src="{{ asset('images/icons/send-dark.svg') }}" alt="" class="h-[17px] w-[17px] hidden dark:block brightness-0 invert" />
                        </button>
                    </div>
                </div>
            </form>
        @endif
        <div class="text-center mt-3 text-[11px] text-[#94A3B8] dark:text-[#64748B]">
            ISTA AI dapat keliru. Mohon verifikasi kembali informasi yang penting.
        </div>
    </div>
</div>
