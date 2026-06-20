<div class="grid grid-cols-1 gap-6 lg:grid-cols-5"
    x-data="{
        copied: null,
        copy(text, id) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                this.copied = id;
                setTimeout(() => { if (this.copied === id) this.copied = null; }, 1500);
            });
        }
    }">

    @if($statusMessage)
        <div class="lg:col-span-5 rounded-xl border border-ista-primary/20 bg-ista-primary/5 px-4 py-3 text-[13px] text-ista-primary dark:border-ista-gold/20 dark:bg-gray-800/60 dark:text-amber-200">
            {{ $statusMessage }}
        </div>
    @endif
    @error('rate_limit')
        <div class="lg:col-span-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 dark:border-red-800/50 dark:bg-red-900/20 dark:text-red-300">{{ $message }}</div>
    @enderror

    {{-- ===== Form generator ===== --}}
    <div class="lg:col-span-3 space-y-5">
        <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
            <h3 class="mb-1 text-sm font-bold text-stone-800 dark:text-gray-100">Prompy Studio</h3>
            <p class="mb-4 text-[11px] text-stone-500 dark:text-gray-400">
                Tulis ide Anda dalam Bahasa Indonesia. Prompy menyusun paket prompt profesional
                (prompt utama dalam Bahasa Inggris + catatan Bahasa Indonesia) untuk Anda salin ke platform AI.
                ISTA AI tidak memanggil platform tersebut atau membuat gambar/video secara langsung.
            </p>

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Ide / permintaan <span class="text-red-500">*</span></label>
                    <textarea wire:model="idea" rows="4" maxlength="{{ \App\Services\Prompts\PromptStudioService::IDEA_MAX_LENGTH }}"
                        placeholder="Mis. Buat poster acara kenegaraan bertema persatuan, nuansa emas dan merah putih."
                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    @error('idea') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="mb-2 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Platform tujuan</label>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach($platforms as $key => $label)
                            <button type="button" wire:click="selectPlatform('{{ $key }}')"
                                class="rounded-xl border px-3 py-2 text-left text-[12px] font-semibold transition-all {{ $platform === $key ? 'border-ista-primary bg-ista-primary/5 text-ista-primary dark:bg-gray-800' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @error('platform') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="mb-2 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Jenis keluaran</label>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach($promptTypes as $key => $label)
                            <button type="button" wire:click="selectPromptType('{{ $key }}')"
                                class="rounded-xl border px-3 py-2 text-center text-[12px] font-semibold transition-all {{ $promptType === $key ? 'border-ista-primary bg-ista-primary/5 text-ista-primary dark:bg-gray-800' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @error('promptType') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Catatan konteks tambahan (opsional)</label>
                    <textarea wire:model="contextNotes" rows="2" maxlength="{{ \App\Services\Prompts\PromptStudioService::CONTEXT_NOTES_MAX_LENGTH }}"
                        placeholder="Mis. gaya formal kenegaraan, hindari elemen politik praktis."
                        class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-[13px] text-stone-800 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    @error('contextNotes') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-stone-600 dark:text-gray-300">Gambar referensi (opsional, privat)</label>
                    <input type="file" wire:model="referenceImage" accept="image/jpeg,image/png,image/webp"
                        class="block w-full text-[12px] text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-ista-primary/10 file:px-3 file:py-1.5 file:text-[12px] file:font-semibold file:text-ista-primary dark:text-gray-300" />
                    <p class="mt-1 text-[11px] text-stone-400 dark:text-gray-500">JPG, PNG, atau WebP. Maks 5 MB. Disimpan privat di akun Anda.</p>
                    <div wire:loading wire:target="referenceImage" class="mt-1 text-[11px] text-ista-primary">Mengunggah gambar...</div>
                    @error('referenceImage') <span class="mt-1 block text-[11px] text-red-500">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Dokumen sumber --}}
        <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
            <h3 class="mb-1 text-sm font-bold text-stone-800 dark:text-gray-100">Dokumen sumber (opsional)</h3>
            <p class="mb-3 text-[11px] text-stone-500 dark:text-gray-400">Hanya dokumen Anda yang sudah siap (ready) yang bisa dipilih. Memakai dokumen akan menandai prompt sebagai konteks internal.</p>
            @if($availableDocuments->isEmpty())
                <p class="text-[12px] text-stone-400 dark:text-gray-500">Belum ada dokumen ready.</p>
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

        {{-- Peringatan konteks internal --}}
        @if(!empty($selectedDocuments) || $referenceImage || trim($contextNotes) !== '')
            <div class="rounded-xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-[12px] text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
                <span class="font-semibold">Perhatian konteks internal.</span>
                Prompt ini menggunakan dokumen/gambar/catatan internal. Periksa kembali sebelum menyalin ke platform AI eksternal — ISTA AI tidak menyensor data sensitif secara otomatis.
            </div>
        @endif

        <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate,referenceImage"
            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-ista-primary px-4 py-2.5 text-[13px] font-bold text-white shadow-sm transition-all hover:bg-ista-primary/90 disabled:opacity-60 sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.4 6.6L23 12l-6.6 2.4L14 21l-2.4-6.6L5 12l6.6-2.4L14 3z" />
            </svg>
            <span wire:loading.remove wire:target="generate">Buat Paket Prompt</span>
            <span wire:loading wire:target="generate">Menyusun...</span>
        </button>
    </div>

    {{-- ===== Riwayat ===== --}}
    <div class="lg:col-span-2">
        <div class="rounded-2xl border border-stone-200/80 bg-white/80 p-5 dark:border-gray-700 dark:bg-gray-800/60">
            <h3 class="mb-3 text-sm font-bold text-stone-800 dark:text-gray-100">Riwayat Prompt</h3>
            @if($prompts->isEmpty())
                <p class="text-[12px] text-stone-400 dark:text-gray-500">Belum ada prompt. Buat paket prompt pertama Anda.</p>
            @else
                <div class="space-y-3">
                    @foreach($prompts as $p)
                        @php $pkg = $p->normalizedPackage(); @endphp
                        <div class="rounded-xl border border-stone-200/70 p-3 dark:border-gray-700 {{ (int) $activePromptId === (int) $p->id ? 'ring-1 ring-ista-primary/30' : '' }}" wire:key="prompt-{{ $p->id }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">{{ $p->title ?: 'Paket Prompt' }}</p>
                                    <p class="text-[11px] text-stone-400 dark:text-gray-500">{{ $p->platform_label }} · {{ $p->prompt_type_label }}</p>
                                </div>
                                @if($p->contains_internal_context)
                                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Konteks internal</span>
                                @endif
                            </div>

                            {{-- Prompt utama --}}
                            <div class="mt-2.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-stone-400 dark:text-gray-500">Prompt utama (EN)</span>
                                    <button type="button" @click="copy(@js($pkg['main_prompt']), 'main-{{ $p->id }}')"
                                        class="rounded-lg bg-ista-primary/10 px-2 py-0.5 text-[10px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                        <span x-show="copied !== 'main-{{ $p->id }}'">Salin</span>
                                        <span x-show="copied === 'main-{{ $p->id }}'" x-cloak>Tersalin ✓</span>
                                    </button>
                                </div>
                                <p class="mt-1 whitespace-pre-wrap rounded-lg bg-stone-50 px-2.5 py-2 text-[12px] text-stone-700 dark:bg-gray-900 dark:text-gray-300">{{ $pkg['main_prompt'] }}</p>
                            </div>

                            {{-- Variants --}}
                            @if(!empty($pkg['variants']))
                                <div class="mt-2 space-y-1.5">
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-stone-400 dark:text-gray-500">Variasi</span>
                                    @foreach($pkg['variants'] as $vi => $variant)
                                        <div class="flex items-start gap-2">
                                            <p class="flex-1 whitespace-pre-wrap rounded-lg bg-stone-50 px-2.5 py-1.5 text-[12px] text-stone-600 dark:bg-gray-900 dark:text-gray-400">{{ $variant }}</p>
                                            <button type="button" @click="copy(@js($variant), 'var-{{ $p->id }}-{{ $vi }}')"
                                                class="shrink-0 rounded-lg bg-ista-primary/10 px-2 py-0.5 text-[10px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                                <span x-show="copied !== 'var-{{ $p->id }}-{{ $vi }}'">Salin</span>
                                                <span x-show="copied === 'var-{{ $p->id }}-{{ $vi }}'" x-cloak>✓</span>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Negative / avoid --}}
                            @if($pkg['negative_prompt'] !== '')
                                <div class="mt-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-stone-400 dark:text-gray-500">Hindari (negative)</span>
                                        <button type="button" @click="copy(@js($pkg['negative_prompt']), 'neg-{{ $p->id }}')"
                                            class="rounded-lg bg-ista-primary/10 px-2 py-0.5 text-[10px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                            <span x-show="copied !== 'neg-{{ $p->id }}'">Salin</span>
                                            <span x-show="copied === 'neg-{{ $p->id }}'" x-cloak>Tersalin ✓</span>
                                        </button>
                                    </div>
                                    <p class="mt-1 whitespace-pre-wrap rounded-lg bg-stone-50 px-2.5 py-1.5 text-[12px] text-stone-600 dark:bg-gray-900 dark:text-gray-400">{{ $pkg['negative_prompt'] }}</p>
                                </div>
                            @endif

                            {{-- Recommended settings --}}
                            @if(!empty($pkg['recommended_settings']))
                                @php $settingsText = collect($pkg['recommended_settings'])->map(fn($v, $k) => $k.': '.$v)->implode("\n"); @endphp
                                <div class="mt-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-stone-400 dark:text-gray-500">Setelan disarankan</span>
                                        <button type="button" @click="copy(@js($settingsText), 'set-{{ $p->id }}')"
                                            class="rounded-lg bg-ista-primary/10 px-2 py-0.5 text-[10px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                            <span x-show="copied !== 'set-{{ $p->id }}'">Salin</span>
                                            <span x-show="copied === 'set-{{ $p->id }}'" x-cloak>Tersalin ✓</span>
                                        </button>
                                    </div>
                                    <dl class="mt-1 grid grid-cols-1 gap-0.5 rounded-lg bg-stone-50 px-2.5 py-1.5 text-[12px] dark:bg-gray-900">
                                        @foreach($pkg['recommended_settings'] as $sk => $sv)
                                            <div class="flex gap-1.5"><dt class="font-semibold text-stone-500 dark:text-gray-400">{{ $sk }}:</dt><dd class="text-stone-700 dark:text-gray-300">{{ $sv }}</dd></div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif

                            {{-- Catatan ID --}}
                            @if($pkg['notes_id'] !== '')
                                <div class="mt-2">
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-stone-400 dark:text-gray-500">Catatan (ID)</span>
                                    <p class="mt-1 whitespace-pre-wrap text-[12px] text-stone-600 dark:text-gray-400">{{ $pkg['notes_id'] }}</p>
                                </div>
                            @endif

                            <div class="mt-2.5 flex justify-end">
                                <button type="button" wire:click="deletePrompt({{ $p->id }})" wire:confirm="Hapus prompt ini?"
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
