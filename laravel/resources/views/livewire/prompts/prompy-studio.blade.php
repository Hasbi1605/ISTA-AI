@php
    $activePackage = $activePackage ?? $activePrompt?->normalizedPackage();
    $activeVersions = $activePrompt?->versions ?? collect();
    $activeVersionNumber = $activeVersion?->version_number;
    $promptBubbleMutedClass = 'whitespace-pre-wrap rounded-2xl border border-stone-200/75 bg-stone-50/90 px-4 py-3 font-mono text-[12px] leading-relaxed text-stone-600 shadow-[0_14px_32px_-30px_rgba(28,25,23,0.42)] dark:border-gray-700/65 dark:bg-gray-800/60 dark:text-gray-400 dark:shadow-none';
    $promptSettingsBubbleClass = 'inline-flex w-fit max-w-full flex-col gap-1 rounded-2xl border border-stone-200/75 bg-stone-50/90 px-4 py-3 font-mono text-[12px] leading-relaxed text-stone-600 shadow-[0_14px_32px_-30px_rgba(28,25,23,0.42)] dark:border-gray-700/65 dark:bg-gray-800/60 dark:text-gray-400 dark:shadow-none';
@endphp

<div
    class="contents"
    x-on:prompy-reference-image-cleared.window="clearReferenceImageState()"
    x-data="{
        copied: null,
        selectedPlatform: @entangle('platform'),
        selectedPromptType: @entangle('promptType'),
        referenceImages: [],
        referenceImageDragging: false,
        referenceImageUploading: false,
        referenceImageUploadFailed: false,
        referenceImageDropError: '',
        copy(text, id) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                this.copied = id;
                setTimeout(() => { if (this.copied === id) this.copied = null; }, 1500);
            });
        },
        chooseReferenceImage() {
            this.$refs.referenceImageInput?.click();
        },
        handleReferenceImageChange(event) {
            this.setReferenceImageFiles(event.target.files || []);
        },
        releaseReferenceImageUrls() {
            this.referenceImages.forEach((image) => {
                if (image.url) URL.revokeObjectURL(image.url);
            });
        },
        setReferenceImageFiles(fileList) {
            const files = Array.from(fileList || []);
            this.referenceImageDropError = '';
            this.referenceImageUploadFailed = false;

            if (files.length > 5) {
                this.referenceImageDropError = 'Gambar referensi maksimal 5 file.';
                this.clearReferenceImageInput();
                return false;
            }

            for (const file of files) {
                if (!this.validateReferenceImageFile(file)) {
                    this.clearReferenceImageInput();
                    return false;
                }
            }

            this.releaseReferenceImageUrls();
            this.referenceImages = files.map((file, index) => ({
                name: file.name,
                label: `Gambar ${index + 1}`,
                url: URL.createObjectURL(file),
            }));

            return true;
        },
        clearReferenceImageState() {
            this.referenceImageDragging = false;
            this.referenceImageUploading = false;
            this.referenceImageUploadFailed = false;
            this.referenceImageDropError = '';
            this.clearReferenceImageInput();
        },
        clearReferenceImageInput() {
            this.releaseReferenceImageUrls();
            this.referenceImages = [];

            if (this.$refs.referenceImageInput) {
                this.$refs.referenceImageInput.value = '';
            }
        },
        validateReferenceImageFile(file) {
            if (!file) return false;

            if (!['image/jpeg', 'image/png'].includes(file.type)) {
                this.referenceImageDropError = 'Gunakan gambar JPG atau PNG.';
                return false;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.referenceImageDropError = 'Ukuran gambar maksimal 5 MB.';
                return false;
            }

            this.referenceImageDropError = '';
            return true;
        },
        dropReferenceImage(event) {
            this.referenceImageDragging = false;
            const files = event.dataTransfer?.files;

            if (files && files.length > 5) {
                this.referenceImageDropError = 'Gambar referensi maksimal 5 file.';
                return;
            }

            if (!this.setReferenceImageFiles(files || [])) {
                return;
            }

            const input = this.$refs.referenceImageInput;
            if (!input) return;

            try {
                const transfer = new DataTransfer();
                Array.from(files || []).forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
            } catch (_) {
                try {
                    input.files = event.dataTransfer.files;
                } catch (_) {
                    this.referenceImageDropError = 'Gagal membaca gambar.';
                    return;
                }
            }

            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        referenceImageCardClass() {
            if (this.referenceImageDragging) {
                return 'border-ista-primary bg-ista-primary/5 text-ista-primary dark:border-amber-400/60 dark:bg-amber-900/10 dark:text-amber-200';
            }

            if (this.referenceImages.length > 0 && !this.referenceImageUploading && !this.referenceImageUploadFailed) {
                return 'border-emerald-300 bg-emerald-50/80 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-200';
            }

            return 'border-stone-200 bg-white text-stone-700 hover:border-ista-primary/40 hover:bg-white dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-amber-400/40';
        }
    }"
>
    @include('livewire.prompts.partials.prompy-history-sidebar')

    <div
        x-show="!isMobile || prompyMobilePanel === 'config'"
        x-cloak
        class="flex flex-col w-full lg:w-[460px] xl:w-[560px] flex-shrink-0 border-r border-stone-200/70 dark:border-[#1E293B] bg-transparent overflow-hidden"
    >
        <div class="h-[61px] flex-shrink-0 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
            <div class="flex min-w-0 items-center gap-2 justify-self-start">
                <button type="button" @click="showPrompySidebar = !showPrompySidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0" aria-label="Toggle Prompy sidebar">
                    <img src="{{ asset('images/icons/collapse-left-light.svg') }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showPrompySidebar ? 'rotate-0' : 'rotate-180'" />
                    <img src="{{ asset('images/icons/collapse-left-dark.svg') }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showPrompySidebar ? 'rotate-0' : 'rotate-180'" />
                </button>
                <div class="group flex min-w-0 items-center">
                    <span class="ista-brand-title text-xl text-ista-primary not-italic transition-transform duration-300 group-hover:scale-105">ISTA <span class="font-light italic text-ista-gold">AI</span></span>
                </div>
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

                <button type="button" @click="showPrompyPreviewPanel()" title="Hasil prompt" aria-label="Buka hasil prompt" class="inline-flex items-center gap-1.5 rounded-[10px] border border-stone-200/70 px-2.5 py-2 text-[11px] font-semibold text-stone-600 transition hover:bg-[#F1F5F9] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h8M8 11h8M8 15h5M5 4h14v16H5z" />
                    </svg>
                    <span>Hasil</span>
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto bg-transparent px-4 py-4 space-y-4" id="prompy-config-box">
            @if($statusMessage)
                <div
                    x-data="{ show: true }"
                    x-show="show"
                    x-init="setTimeout(() => { show = false; $wire.set('statusMessage', null); }, 5000)"
                    class="relative rounded-lg border border-ista-primary/20 bg-ista-primary/5 pl-3 pr-8 py-2 text-[12px] font-semibold text-ista-primary dark:border-ista-gold/20 dark:bg-gray-800/60 dark:text-amber-200"
                >
                    <span>{{ $statusMessage }}</span>
                    <button
                        type="button"
                        @click="show = false; $wire.set('statusMessage', null)"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-ista-primary/70 hover:text-ista-primary dark:text-amber-200/70 dark:hover:text-amber-200 transition-colors"
                        aria-label="Tutup pesan"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif
            @error('rate_limit')
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-700 dark:border-red-800/50 dark:bg-red-900/20 dark:text-red-300">{{ $message }}</div>
            @enderror
            @if ($errors->any())
                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-left dark:border-rose-900/60 dark:bg-rose-950/30">
                    <p class="text-[12px] font-semibold text-rose-700 dark:text-rose-300">Belum bisa membuat paket prompt.</p>
                    <p class="mt-0.5 text-[11.5px] leading-relaxed text-rose-600 dark:text-rose-300">{{ $errors->first() }}</p>
                </div>
            @endif

            @if(! $activePrompt)
            <form id="prompy-form" wire:submit.prevent="generate" class="chat-form memo-config-panel">
                <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="mt-1 text-[15px] font-bold text-stone-900 dark:text-gray-100">Prompy Studio</h2>
                    <p class="mt-1 max-w-[26rem] text-[12px] leading-relaxed text-stone-500 dark:text-gray-400">Tulis ide, pilih target, lalu salin paket prompt dari panel hasil.</p>
                </div>

                <div class="memo-config-section bg-stone-50/65 dark:bg-gray-950/20">
                    <div>
                        <label class="memo-config-label">Ide / permintaan <span class="text-red-500">*</span></label>
                        <textarea wire:model="idea" rows="4" maxlength="{{ \App\Services\Prompts\PromptStudioService::IDEA_MAX_LENGTH }}"
                            placeholder="Mis. Buat poster acara kenegaraan bertema persatuan, nuansa emas dan merah putih."
                            class="memo-config-textarea"></textarea>
                        @error('idea') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Platform tujuan</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($platforms as $key => $label)
                                <button type="button"
                                    @click="selectedPlatform = @js($key)"
                                    :aria-pressed="selectedPlatform === @js($key) ? 'true' : 'false'"
                                    :class="selectedPlatform === @js($key) ? 'border-ista-primary bg-ista-primary/[0.04] text-stone-800 ring-1 ring-ista-primary/20 dark:border-ista-primary dark:bg-gray-800/80 dark:text-gray-100 dark:ring-ista-primary/40' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300'"
                                    class="flex min-h-10 items-center gap-2.5 rounded-md border px-3 py-2 text-left text-[12px] font-semibold transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary">
                                    @include('livewire.prompts.partials.prompy-platform-icon', ['platformKey' => $key])
                                    <span class="min-w-0 truncate">{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>
                        @error('platform') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Jenis keluaran</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($promptTypes as $key => $label)
                                <button type="button"
                                    @click="selectedPromptType = @js($key)"
                                    :aria-pressed="selectedPromptType === @js($key) ? 'true' : 'false'"
                                    :class="selectedPromptType === @js($key) ? 'border-ista-primary bg-ista-primary/[0.04] text-stone-800 ring-1 ring-ista-primary/20 dark:border-ista-primary dark:bg-gray-800/80 dark:text-gray-100 dark:ring-ista-primary/40' : 'border-stone-200 text-stone-600 hover:border-ista-primary/40 dark:border-gray-700 dark:text-gray-300'"
                                    class="min-h-10 rounded-md border px-3 py-2 text-left text-[12px] font-semibold transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        @error('promptType') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Catatan konteks tambahan</label>
                        <textarea wire:model="contextNotes" rows="2" maxlength="{{ \App\Services\Prompts\PromptStudioService::CONTEXT_NOTES_MAX_LENGTH }}"
                            placeholder="Opsional: gaya formal kenegaraan, hindari elemen politik praktis."
                            class="memo-config-textarea"></textarea>
                        @error('contextNotes') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Gambar referensi</label>
                        <input
                            x-ref="referenceImageInput"
                            type="file"
                            wire:model="referenceImages"
                            accept="image/jpeg,image/png"
                            multiple
                            class="sr-only"
                            x-on:change="handleReferenceImageChange($event)"
                            x-on:livewire-upload-start="referenceImageUploading = true; referenceImageUploadFailed = false"
                            x-on:livewire-upload-finish="referenceImageUploading = false"
                            x-on:livewire-upload-error="referenceImageUploading = false; referenceImageUploadFailed = true; clearReferenceImageInput()"
                        />
                        <button
                            type="button"
                            @click="chooseReferenceImage()"
                            @dragenter.prevent="referenceImageDragging = true"
                            @dragover.prevent="referenceImageDragging = true"
                            @dragleave.prevent="referenceImageDragging = false"
                            @drop.prevent="dropReferenceImage($event)"
                            :class="referenceImageCardClass()"
                            class="group flex w-full items-center gap-3 rounded-lg border border-dashed px-3 py-2.5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-current/15 bg-white/70 dark:bg-gray-950/30">
                                <svg x-show="!referenceImageUploading && (referenceImages.length === 0 || referenceImageUploadFailed)" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 16V4m0 0l-4 4m4-4l4 4M4 17v1.5A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5V17" />
                                </svg>
                                <span x-show="referenceImageUploading" x-cloak class="h-4 w-4 rounded-full border-2 border-current/50 border-t-transparent animate-spin" aria-hidden="true"></span>
                                <svg x-show="referenceImages.length > 0 && !referenceImageUploading && !referenceImageUploadFailed" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[12px] font-semibold" x-text="referenceImages.length ? `${referenceImages.length} gambar dipilih` : 'Pilih atau seret gambar'"></span>
                                <span class="mt-0.5 block text-[11px] leading-relaxed opacity-75">Opsional. JPG/PNG, maksimal 5 gambar, masing-masing 5 MB.</span>
                            </span>
                        </button>
                        <div x-show="referenceImages.length > 0" x-cloak class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="image in referenceImages" :key="image.label">
                                <div class="overflow-hidden rounded-lg border border-stone-200 bg-white text-left shadow-sm dark:border-gray-700 dark:bg-gray-900">
                                    <div class="aspect-[4/3] bg-stone-100 dark:bg-gray-800">
                                        <img :src="image.url" :alt="image.label" class="h-full w-full object-cover">
                                    </div>
                                    <div class="min-w-0 px-2 py-1.5">
                                        <p class="truncate text-[11px] font-bold text-stone-700 dark:text-gray-200" x-text="image.label"></p>
                                        <p class="truncate text-[10.5px] text-stone-400 dark:text-gray-500" x-text="image.name"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <p x-show="referenceImageDropError" x-cloak class="memo-config-error" x-text="referenceImageDropError"></p>
                        @error('referenceImages') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('referenceImages.*') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </form>
            @else
                <div class="memo-config-panel">
                    <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="mt-1 text-[15px] font-bold text-stone-900 dark:text-gray-100">Revisi Prompt</h2>
                                <p class="mt-1 max-w-[26rem] text-[12px] leading-relaxed text-stone-500 dark:text-gray-400">Kirim instruksi revisi. Output baru akan menjadi versi berikutnya di panel hasil.</p>
                            </div>
                            @if($activeVersionNumber)
                                <span class="shrink-0 rounded-full bg-ista-primary/10 px-2.5 py-1 text-[11px] font-bold text-ista-primary dark:bg-amber-300/10 dark:text-amber-200">v{{ $activeVersionNumber }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="memo-config-section space-y-3 bg-stone-50/65 dark:bg-gray-950/20">
                        <div class="rounded-xl border border-stone-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                            <p class="text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Prompt aktif</p>
                            <p class="mt-1 truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">{{ $activePrompt->displayTitle() }}</p>
                            <p class="mt-1 text-[11px] text-stone-500 dark:text-gray-400">{{ $activePrompt->platform_label }} · {{ $activePrompt->prompt_type_label }}</p>
                        </div>

                        @if($activeVersions->isNotEmpty())
                            <div>
                                <p class="memo-config-label">Versi prompt</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($activeVersions as $version)
                                        <button
                                            type="button"
                                            wire:click="selectPromptVersion({{ $version->id }})"
                                            class="rounded-full border px-3 py-1.5 text-[11px] font-bold transition {{ (int) $activeVersionId === (int) $version->id ? 'border-ista-primary bg-ista-primary text-white shadow-sm' : 'border-stone-200 bg-white text-stone-600 hover:border-ista-primary/50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300' }}"
                                        >
                                            v{{ $version->version_number }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="space-y-3">
                            <div class="flex justify-start">
                                <div class="max-w-[88%] rounded-2xl rounded-tl-md border border-stone-200 bg-white px-3 py-2.5 text-[12.5px] leading-relaxed text-stone-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                    <p class="font-semibold text-stone-800 dark:text-gray-100">Prompy Studio</p>
                                    <p class="mt-1">Versi {{ $activeVersionNumber ?: 1 }} siap. Minta revisi seperti chat, misalnya "buat lebih formal", "pendekkan prompt", atau "gunakan Gambar 1 sebagai subjek dan gaya Gambar 2".</p>
                                </div>
                            </div>

                            @foreach($activeVersions as $version)
                                @if($version->revision_instruction)
                                    <div class="flex justify-end">
                                        <div class="max-w-[88%] rounded-2xl rounded-tr-md bg-ista-primary px-3 py-2.5 text-[12.5px] leading-relaxed text-white shadow-sm">
                                            {{ $version->revision_instruction }}
                                        </div>
                                    </div>
                                    <div class="flex justify-start">
                                        <div class="max-w-[88%] rounded-2xl rounded-tl-md border border-stone-200 bg-white px-3 py-2.5 text-[12.5px] leading-relaxed text-stone-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                            Versi {{ $version->version_number }} sudah dibuat dan bisa disalin dari panel hasil.
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="chat-composer-safe flex-shrink-0 px-4 pt-2 bg-transparent w-full">
            <div class="rounded-lg border border-stone-200 bg-white p-2 shadow-[0_-10px_30px_-24px_rgba(28,25,23,0.45)] dark:border-gray-800 dark:bg-gray-900">
                @if($activePrompt)
                    <form id="prompy-revision-form" wire:submit.prevent="revisePrompt" class="space-y-2">
                        <label for="prompy-revision-input" class="sr-only">Instruksi revisi prompt</label>
                        <textarea
                            id="prompy-revision-input"
                            wire:model="revisionInstruction"
                            rows="2"
                            maxlength="{{ \App\Services\Prompts\PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH }}"
                            placeholder="Contoh: gunakan Gambar 1 sebagai subjek utama, tiru gaya visual Gambar 2, lalu buat hasilnya menjadi pas foto formal berlatar merah."
                            class="memo-config-textarea min-h-[74px] resize-none"
                        ></textarea>
                        @error('revisionInstruction') <p class="memo-config-error">{{ $message }}</p> @enderror
                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="revisePrompt"
                                @click="showPrompyPreviewPanel()"
                                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-ista-primary px-4 text-[13px] font-semibold text-white shadow-sm transition hover:bg-ista-dark active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50">
                            <span wire:loading.remove wire:target="revisePrompt">Revisi Prompt</span>
                            <span wire:loading.inline-flex wire:target="revisePrompt" class="items-center gap-2">
                                <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                                <span>Merevisi prompt...</span>
                            </span>
                        </button>
                    </form>
                @else
                    <button type="submit"
                            form="prompy-form"
                            wire:loading.attr="disabled"
                            wire:target="generate,referenceImages"
                            @click="showPrompyPreviewPanel()"
                            class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-ista-primary px-4 text-[13px] font-semibold text-white shadow-sm transition hover:bg-ista-dark active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="generate">Buat Prompt</span>
                        <span wire:loading.inline-flex wire:target="generate" class="items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                            <span>Menyusun prompt...</span>
                        </span>
                    </button>
                @endif
            </div>
            <div class="text-center mt-3 text-[11px] text-[#94A3B8] dark:text-[#64748B]">
                ISTA AI dapat keliru. Mohon verifikasi kembali informasi yang penting.
            </div>
        </div>
    </div>

    <div x-show="!isMobile || prompyMobilePanel === 'preview'" x-cloak class="flex-1 flex flex-col min-w-0 bg-stone-50 dark:bg-gray-950 overflow-hidden">
        <div class="relative z-30 min-h-[61px] flex-shrink-0 flex items-center justify-between gap-2 px-3 sm:px-5 border-b border-stone-200/60 bg-white/85 backdrop-blur-sm dark:border-[#1E293B]/70 dark:bg-gray-800/85">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" @click="showPrompyConfigPanel()" class="inline-flex items-center justify-center rounded-lg border border-stone-200 bg-white p-2 text-stone-600 shadow-sm transition hover:bg-stone-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 lg:hidden" aria-label="Kembali ke form prompt" title="Kembali">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div class="inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 py-1 text-[12.5px] font-semibold text-stone-800 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h8M8 11h8M8 15h5M5 4h14v16H5z" />
                    </svg>
                    <span class="hidden sm:inline">Prompt</span>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-stone-800 dark:text-gray-100">{{ $activePrompt?->displayTitle() ?: 'Hasil Prompy Studio' }}</p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-stone-500 dark:text-gray-400">
                        <span>{{ $activePrompt ? (($activeVersionNumber ? 'v'.$activeVersionNumber.' · ' : '').$activePrompt->platform_label.' · '.$activePrompt->prompt_type_label) : 'Output prompt tampil di sini' }}</span>
                    </div>
                </div>
            </div>

            @if($activePackage)
                @php
                    $settingsText = collect($activePackage['recommended_settings'] ?? [])->map(fn($v, $k) => $k.': '.$v)->implode("\n");
                    $allText = collect([
                        $activePackage['main_prompt'] ?? '',
                        !empty($activePackage['variants']) ? "Variants:\n".implode("\n\n", $activePackage['variants']) : '',
                        !empty($activePackage['negative_prompt']) ? "Negative:\n".$activePackage['negative_prompt'] : '',
                        $settingsText !== '' ? "Settings:\n".$settingsText : '',
                        !empty($activePackage['notes_id']) ? "Notes:\n".$activePackage['notes_id'] : '',
                    ])->filter()->implode("\n\n");
                @endphp
                <button type="button" @click="copy(@js($allText), 'all-active-prompt')"
                    class="inline-flex items-center gap-1 rounded-lg bg-ista-primary px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-ista-dark">
                    <span x-show="copied !== 'all-active-prompt'">Salin semua</span>
                    <span x-show="copied === 'all-active-prompt'" x-cloak>Tersalin</span>
                </button>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto px-4 py-4">
            <div wire:loading.flex wire:target="generate,revisePrompt" class="min-h-[420px] items-center justify-center px-6 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.75)] dark:bg-gray-900">
                        <div class="relative flex h-12 w-12 items-center justify-center rounded-full">
                            <span class="absolute inset-0 rounded-full border-2 border-ista-primary/25 border-t-ista-primary animate-spin"></span>
                            <img src="{{ asset('images/ista/logo.png') }}" alt="" class="h-8 w-8 object-contain" />
                        </div>
                    </div>
                    <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Menyusun paket prompt...</h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-stone-500 dark:text-gray-400">
                        <span class="ista-loading-shimmer ista-label-enter">Mengolah ide dan konteks terpilih</span>
                    </p>
                </div>
            </div>

            <div wire:loading.remove wire:target="generate,revisePrompt" class="space-y-4">
                @if($activePackage)
                    <div class="memo-config-panel">
                        <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Prompt utama</p>
                                    <h3 class="mt-1 truncate text-[15px] font-bold text-stone-900 dark:text-gray-100">{{ $activePrompt?->displayTitle() ?: 'Prompt' }}</h3>
                                </div>
                            </div>
                        </div>

                        <div class="memo-config-section">
                            <div class="flex items-center justify-between gap-3">
                                <span class="memo-config-label mb-0">Prompt utama</span>
                                <button type="button" @click="copy(@js($activePackage['main_prompt'] ?? ''), 'main-active-prompt')"
                                    class="rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                    <span x-show="copied !== 'main-active-prompt'">Salin</span>
                                    <span x-show="copied === 'main-active-prompt'" x-cloak>Tersalin</span>
                                </button>
                            </div>
                            <p class="mt-2 {{ $promptBubbleMutedClass }}">{{ $activePackage['main_prompt'] ?? '' }}</p>
                        </div>

                        @if(!empty($activePackage['variants']))
                            <div class="memo-config-section">
                                <span class="memo-config-label">Variasi</span>
                                <div class="space-y-2">
                                    @foreach($activePackage['variants'] as $vi => $variant)
                                        <div class="flex items-start gap-2">
                                            <p class="flex-1 {{ $promptBubbleMutedClass }}">{{ $variant }}</p>
                                            <button type="button" @click="copy(@js($variant), 'active-var-{{ $vi }}')"
                                                class="shrink-0 rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                                <span x-show="copied !== 'active-var-{{ $vi }}'">Salin</span>
                                                <span x-show="copied === 'active-var-{{ $vi }}'" x-cloak>Tersalin</span>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(($activePackage['negative_prompt'] ?? '') !== '')
                            <div class="memo-config-section">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="memo-config-label mb-0">Hindari (negative)</span>
                                    <button type="button" @click="copy(@js($activePackage['negative_prompt']), 'active-negative')"
                                        class="rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                        <span x-show="copied !== 'active-negative'">Salin</span>
                                        <span x-show="copied === 'active-negative'" x-cloak>Tersalin</span>
                                    </button>
                                </div>
                                <p class="mt-2 {{ $promptBubbleMutedClass }}">{{ $activePackage['negative_prompt'] }}</p>
                            </div>
                        @endif

                        @if(!empty($activePackage['recommended_settings']))
                            @php $settingsText = collect($activePackage['recommended_settings'])->map(fn($v, $k) => $k.': '.$v)->implode("\n"); @endphp
                            <div class="memo-config-section">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="memo-config-label mb-0">Setelan disarankan</span>
                                    <button type="button" @click="copy(@js($settingsText), 'active-settings')"
                                        class="rounded-lg bg-ista-primary/10 px-2.5 py-1 text-[11px] font-semibold text-ista-primary hover:bg-ista-primary/15">
                                        <span x-show="copied !== 'active-settings'">Salin</span>
                                        <span x-show="copied === 'active-settings'" x-cloak>Tersalin</span>
                                    </button>
                                </div>
                                <div class="mt-2 {{ $promptSettingsBubbleClass }}">
                                    @foreach($activePackage['recommended_settings'] as $sk => $sv)
                                        <div class="max-w-full break-words">{{ $sk }}: {{ $sv }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(($activePackage['notes_id'] ?? '') !== '')
                            <div class="memo-config-section">
                                <span class="memo-config-label">Catatan</span>
                                <p class="mt-1 whitespace-pre-wrap text-[12.5px] leading-relaxed text-stone-600 dark:text-gray-400">{{ $activePackage['notes_id'] }}</p>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex min-h-[420px] items-center justify-center px-6 text-center">
                        <div class="max-w-sm">
                            <div class="mx-auto h-16 w-16 rounded-2xl bg-stone-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-stone-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h8M8 11h8M8 15h5M5 4h14v16H5z" />
                                </svg>
                            </div>
                            <h3 class="text-[15px] font-semibold text-stone-700 dark:text-gray-300">Belum ada paket prompt</h3>
                            <p class="mt-2 text-[13px] text-stone-500 dark:text-gray-400 leading-relaxed">Isi ide di panel kiri. Prompt utama, variasi, negative prompt, dan setelan salin akan tampil di sini.</p>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>
