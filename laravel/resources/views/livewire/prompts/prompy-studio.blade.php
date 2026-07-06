@php
    $activePackage = $activePackage ?? $activePrompt?->normalizedPackage();
    $activeVersions = $activePrompt?->versions ?? collect();
    $activeVersionNumber = $activeVersion?->version_number;
    $activeReferenceImages = collect(is_array($activeVersion?->reference_images) ? $activeVersion->reference_images : [])
        ->filter(fn($image) => is_array($image) && !empty($image['path']))
        ->map(fn($image, $index) => [
            'label' => (string) ($image['label'] ?? 'Gambar '.($index + 1)),
            'size' => (int) ($image['size'] ?? 0),
            'url' => $activePrompt ? route('prompts.reference-image', ['prompt' => $activePrompt->id, 'imageIndex' => $index, 'version' => $activeVersion?->id]) : '',
        ])
        ->values();
    if ($activeReferenceImages->isEmpty() && $activePrompt?->reference_image_path) {
        $activeReferenceImages = collect([[
            'label' => 'Gambar 1',
            'size' => (int) $activePrompt->reference_image_size_bytes,
            'url' => route('prompts.reference-image', ['prompt' => $activePrompt->id, 'imageIndex' => 0, 'version' => $activeVersion?->id]),
        ]]);
    }
    $promptBubbleMutedClass = 'whitespace-pre-wrap rounded-2xl border border-stone-200/75 bg-stone-50/90 px-4 py-3 font-mono text-[12px] leading-relaxed text-stone-600 shadow-[0_14px_32px_-30px_rgba(28,25,23,0.42)] dark:border-gray-700/65 dark:bg-gray-800/60 dark:text-gray-400 dark:shadow-none';
    $promptSettingsBubbleClass = 'inline-flex w-fit max-w-full flex-col gap-1 rounded-2xl border border-stone-200/75 bg-stone-50/90 px-4 py-3 font-mono text-[12px] leading-relaxed text-stone-600 shadow-[0_14px_32px_-30px_rgba(28,25,23,0.42)] dark:border-gray-700/65 dark:bg-gray-800/60 dark:text-gray-400 dark:shadow-none';
@endphp

<div
    class="contents"
    x-on:prompy-reference-image-cleared.window="clearReferenceImageState()"
    x-on:prompy-reference-document-cleared.window="clearReferenceDocumentState()"
    x-on:prompy-revision-reference-image-cleared.window="clearRevisionReferenceImageState()"
    x-on:prompy-revision-reference-document-cleared.window="clearRevisionReferenceDocumentState()"
    x-on:prompt-loading.window="isSwitchingPrompt = true"
    x-on:prompt-loaded.window="isSwitchingPrompt = false"
    x-data="{
        copied: null,
        selectedPlatform: @entangle('platform'),
        selectedPromptType: @entangle('promptType'),
        referenceImages: [],
        referenceImageFiles: [],
        referenceImageSyncing: false,
        referenceImageDragging: false,
        referenceImageUploading: false,
        referenceImageUploadFailed: false,
        referenceImageDropError: '',
        referenceImageSortable: null,
        referenceDocuments: [],
        referenceDocumentFiles: [],
        referenceDocumentSyncing: false,
        referenceDocumentUploading: false,
        referenceDocumentUploadFailed: false,
        referenceDocumentDropError: '',
        revisionReferenceImages: [],
        revisionReferenceImageFiles: [],
        revisionReferenceImageSyncing: false,
        revisionReferenceImageUploading: false,
        revisionReferenceImageUploadFailed: false,
        revisionReferenceImageDropError: '',
        revisionReferenceImageSortable: null,
        revisionReferenceDocuments: [],
        revisionReferenceDocumentFiles: [],
        revisionReferenceDocumentSyncing: false,
        revisionReferenceDocumentUploading: false,
        revisionReferenceDocumentUploadFailed: false,
        revisionReferenceDocumentDropError: '',
        prompyRevisionText: '',
        prompyRevisionLoading: false,
        isSwitchingPrompt: false,
        activeRefImageModalUrl: null,
        activeRefImageModalLabel: '',
        excludedActiveRefImages: @entangle('excludedActiveReferenceImages'),
        copy(text, id) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                this.copied = id;
                setTimeout(() => { if (this.copied === id) this.copied = null; }, 1500);
            });
        },
        openReferenceImageModal(image) {
            if (!image?.url) return;
            this.activeRefImageModalUrl = image.url;
            this.activeRefImageModalLabel = image.name ? `${image.label} - ${image.name}` : image.label;
        },
        scrollPrompyChatToBottom() {
            this.$nextTick(() => {
                const box = this.$refs.prompyChatBox;
                if (box) box.scrollTop = box.scrollHeight;
            });
        },
        async submitPrompyRevision($wire, textarea) {
            const message = (textarea?.value || '').trim();

            if (!message || this.prompyRevisionLoading || this.revisionReferenceImageUploading || this.revisionReferenceDocumentUploading || $wire.isGenerating) {
                return;
            }

            this.prompyRevisionText = message;
            this.prompyRevisionLoading = true;
            this.scrollPrompyChatToBottom();

            try {
                textarea.value = '';
                textarea.style.height = 'auto';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                this.scrollPrompyChatToBottom();

                await $wire.sendPromptChat(message);
            } catch (error) {
                textarea.value = message;
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            } finally {
                this.prompyRevisionText = '';
                this.prompyRevisionLoading = false;
                this.scrollPrompyChatToBottom();
            }
        },
        chooseReferenceImage() {
            this.$refs.referenceImageInput?.click();
        },
        chooseReferenceDocument() {
            this.$refs.referenceDocumentInput?.click();
        },
        chooseRevisionReferenceImage() {
            this.$refs.revisionReferenceImageInput?.click();
        },
        chooseRevisionReferenceDocument() {
            this.$refs.revisionReferenceDocumentInput?.click();
        },
        handleReferenceImageChange(event) {
            if (this.referenceImageSyncing) return;
            const files = event.target.files || [];
            if (files.length === 0) return;
            this.setReferenceImageFiles(files);
        },
        handleReferenceDocumentChange(event) {
            if (this.referenceDocumentSyncing) return;
            this.setReferenceDocumentFiles(event.target.files || []);
        },
        handleRevisionReferenceImageChange(event) {
            if (this.revisionReferenceImageSyncing) return;
            const files = event.target.files || [];
            if (files.length === 0) return;
            this.setRevisionReferenceImageFiles(files);
        },
        handleRevisionReferenceDocumentChange(event) {
            if (this.revisionReferenceDocumentSyncing) return;
            this.setRevisionReferenceDocumentFiles(event.target.files || []);
        },
        releaseReferenceImageUrls() {
            this.referenceImages.forEach((image) => {
                if (image.url) URL.revokeObjectURL(image.url);
            });
        },
        releaseRevisionReferenceImageUrls() {
            this.revisionReferenceImages.forEach((image) => {
                if (image.url) URL.revokeObjectURL(image.url);
            });
        },
        referenceImageUid(file) {
            if (!file) return `ref-${Math.random().toString(36).slice(2, 10)}`;
            if (!file.__prompyUid) {
                file.__prompyUid = (window.crypto?.randomUUID?.() || `ref-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`);
            }
            return file.__prompyUid;
        },
        restoreSortableDom(evt) {
            const list = evt?.from;
            const node = evt?.item;
            if (!list || !node) return;
            // Revert SortableJS' DOM mutation so Alpine stays the single source of truth.
            list.removeChild(node);
            const reference = list.children[evt.oldIndex] || null;
            list.insertBefore(node, reference);
        },
        relabelReferenceImages(images) {
            return images.map((image, i) => ({ ...image, label: `Gambar ${i + 1}` }));
        },
        moveReferenceImage(oldIndex, newIndex) {
            const total = this.referenceImageFiles.length;
            if (oldIndex === newIndex) return;
            if (oldIndex < 0 || newIndex < 0 || oldIndex >= total || newIndex >= total) return;
            const [movedFile] = this.referenceImageFiles.splice(oldIndex, 1);
            this.referenceImageFiles.splice(newIndex, 0, movedFile);
            const [movedImage] = this.referenceImages.splice(oldIndex, 1);
            this.referenceImages.splice(newIndex, 0, movedImage);
            this.referenceImages = this.relabelReferenceImages(this.referenceImages);
            this.syncReferenceImageInput();
        },
        moveRevisionReferenceImage(oldIndex, newIndex) {
            const total = this.revisionReferenceImageFiles.length;
            if (oldIndex === newIndex) return;
            if (oldIndex < 0 || newIndex < 0 || oldIndex >= total || newIndex >= total) return;
            const [movedFile] = this.revisionReferenceImageFiles.splice(oldIndex, 1);
            this.revisionReferenceImageFiles.splice(newIndex, 0, movedFile);
            const [movedImage] = this.revisionReferenceImages.splice(oldIndex, 1);
            this.revisionReferenceImages.splice(newIndex, 0, movedImage);
            this.revisionReferenceImages = this.relabelReferenceImages(this.revisionReferenceImages);
            this.syncRevisionReferenceImageInput();
        },
        destroyReferenceImageSortable() {
            if (!this.referenceImageSortable) return;
            this.referenceImageSortable.destroy();
            this.referenceImageSortable = null;
        },
        destroyRevisionReferenceImageSortable() {
            if (!this.revisionReferenceImageSortable) return;
            this.revisionReferenceImageSortable.destroy();
            this.revisionReferenceImageSortable = null;
        },
        initReferenceImageSortable(el) {
            if (!el) return;

            window.ensureIstaSortable?.().then((Sortable) => {
                if (!Sortable || !el.isConnected) return;

                this.destroyReferenceImageSortable();
                this.referenceImageSortable = Sortable.create(el, {
                animation: 160,
                delay: 160,
                delayOnTouchOnly: true,
                touchStartThreshold: 6,
                draggable: '[data-ref-image-item]',
                filter: '[data-no-drag]',
                preventOnFilter: false,
                ghostClass: 'opacity-40',
                onEnd: (evt) => {
                    if (evt.oldIndex == null || evt.newIndex == null || evt.oldIndex === evt.newIndex) return;
                    this.restoreSortableDom(evt);
                    this.moveReferenceImage(evt.oldIndex, evt.newIndex);
                },
            });
            });
        },
        initRevisionReferenceImageSortable(el) {
            if (!el) return;

            window.ensureIstaSortable?.().then((Sortable) => {
                if (!Sortable || !el.isConnected) return;

                this.destroyRevisionReferenceImageSortable();
                this.revisionReferenceImageSortable = Sortable.create(el, {
                animation: 160,
                delay: 160,
                delayOnTouchOnly: true,
                touchStartThreshold: 6,
                draggable: '[data-ref-image-item]',
                filter: '[data-no-drag]',
                preventOnFilter: false,
                ghostClass: 'opacity-40',
                onEnd: (evt) => {
                    if (evt.oldIndex == null || evt.newIndex == null || evt.oldIndex === evt.newIndex) return;
                    this.restoreSortableDom(evt);
                    this.moveRevisionReferenceImage(evt.oldIndex, evt.newIndex);
                },
            });
            });
        },
        setReferenceImageFiles(fileList) {
            const newFiles = Array.from(fileList || []);
            this.referenceImageDropError = '';
            this.referenceImageUploadFailed = false;

            for (const file of newFiles) {
                if (!this.validateReferenceImageFile(file)) {
                    this.clearReferenceImageInput();
                    return false;
                }
            }

            const merged = [...this.referenceImageFiles, ...newFiles];
            if (merged.length > 5) {
                this.referenceImageDropError = 'Gambar referensi maksimal 5 file.';
                this.clearReferenceImageInput();
                return false;
            }

            this.referenceImageFiles = merged;
            this.referenceImages = merged.map((file, index) => ({
                id: this.referenceImageUid(file),
                name: file.name,
                label: `Gambar ${index + 1}`,
                url: URL.createObjectURL(file),
            }));

            this.syncReferenceImageInput();
            return true;
        },
        removeReferenceImageAt(index) {
            if (index < 0 || index >= this.referenceImageFiles.length) return;
            const removedImage = this.referenceImages[index] || null;
            if (removedImage?.url && this.activeRefImageModalUrl === removedImage.url) {
                this.activeRefImageModalUrl = null;
                this.activeRefImageModalLabel = '';
            }
            if (removedImage?.url) URL.revokeObjectURL(removedImage.url);
            this.referenceImageFiles.splice(index, 1);
            this.referenceImages = this.referenceImageFiles.map((file, i) => ({
                id: this.referenceImageUid(file),
                name: file.name,
                label: `Gambar ${i + 1}`,
                url: URL.createObjectURL(file),
            }));
            this.syncReferenceImageInput();
            if (this.referenceImageFiles.length === 0) {
                this.$wire.set('referenceImages', []);
            }
        },
        syncReferenceImageInput() {
            const input = this.$refs.referenceImageInput;
            if (!input) return;
            try {
                const transfer = new DataTransfer();
                this.referenceImageFiles.forEach((file) => transfer.items.add(file));
                this.referenceImageSyncing = true;
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (_) {
            } finally {
                this.referenceImageSyncing = false;
            }
        },
        setReferenceDocumentFiles(fileList) {
            const newFiles = Array.from(fileList || []);
            this.referenceDocumentDropError = '';
            this.referenceDocumentUploadFailed = false;

            for (const file of newFiles) {
                if (!this.validateReferenceDocumentFile(file)) {
                    this.syncReferenceDocumentInput();
                    return false;
                }
            }

            const merged = [...this.referenceDocumentFiles, ...newFiles];
            if (merged.length > 3) {
                this.referenceDocumentDropError = 'Dokumen acuan maksimal 3 file.';
                this.syncReferenceDocumentInput();
                return false;
            }

            this.referenceDocumentFiles = merged;
            this.referenceDocuments = merged.map((file, index) => ({
                name: file.name,
                label: `Dokumen ${index + 1}`,
            }));

            this.syncReferenceDocumentInput();
            return true;
        },
        syncReferenceDocumentInput() {
            const input = this.$refs.referenceDocumentInput;
            if (!input) return;
            try {
                const transfer = new DataTransfer();
                this.referenceDocumentFiles.forEach((file) => transfer.items.add(file));
                this.referenceDocumentSyncing = true;
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (_) {
            } finally {
                this.referenceDocumentSyncing = false;
            }
        },
        setRevisionReferenceImageFiles(fileList) {
            const newFiles = Array.from(fileList || []);
            this.revisionReferenceImageDropError = '';
            this.revisionReferenceImageUploadFailed = false;

            for (const file of newFiles) {
                if (!this.validateRevisionReferenceImageFile(file)) {
                    this.clearRevisionReferenceImageInput();
                    return false;
                }
            }

            const merged = [...this.revisionReferenceImageFiles, ...newFiles];
            if (merged.length > 5) {
                this.revisionReferenceImageDropError = 'Gambar revisi maksimal 5 file.';
                this.clearRevisionReferenceImageInput();
                return false;
            }

            this.revisionReferenceImageFiles = merged;
            this.revisionReferenceImages = merged.map((file, index) => ({
                id: this.referenceImageUid(file),
                name: file.name,
                label: `Gambar ${index + 1}`,
                url: URL.createObjectURL(file),
            }));

            this.syncRevisionReferenceImageInput();
            return true;
        },
        removeRevisionReferenceImageAt(index) {
            if (index < 0 || index >= this.revisionReferenceImageFiles.length) return;
            if (this.revisionReferenceImages[index]?.url) URL.revokeObjectURL(this.revisionReferenceImages[index].url);
            this.revisionReferenceImageFiles.splice(index, 1);
            this.revisionReferenceImages = this.revisionReferenceImageFiles.map((file, i) => ({
                id: this.referenceImageUid(file),
                name: file.name,
                label: `Gambar ${i + 1}`,
                url: URL.createObjectURL(file),
            }));
            this.syncRevisionReferenceImageInput();
            if (this.revisionReferenceImageFiles.length === 0) {
                this.$wire.clearRevisionReferenceImages();
            }
        },
        syncRevisionReferenceImageInput() {
            const input = this.$refs.revisionReferenceImageInput;
            if (!input) return;
            try {
                const transfer = new DataTransfer();
                this.revisionReferenceImageFiles.forEach((file) => transfer.items.add(file));
                this.revisionReferenceImageSyncing = true;
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (_) {
            } finally {
                this.revisionReferenceImageSyncing = false;
            }
        },
        setRevisionReferenceDocumentFiles(fileList) {
            const newFiles = Array.from(fileList || []);
            this.revisionReferenceDocumentDropError = '';
            this.revisionReferenceDocumentUploadFailed = false;

            for (const file of newFiles) {
                if (!this.validateRevisionReferenceDocumentFile(file)) {
                    this.syncRevisionReferenceDocumentInput();
                    return false;
                }
            }

            const merged = [...this.revisionReferenceDocumentFiles, ...newFiles];
            if (merged.length > 3) {
                this.revisionReferenceDocumentDropError = 'Dokumen revisi maksimal 3 file.';
                this.syncRevisionReferenceDocumentInput();
                return false;
            }

            this.revisionReferenceDocumentFiles = merged;
            this.revisionReferenceDocuments = merged.map((file, index) => ({
                name: file.name,
                label: `Dokumen ${index + 1}`,
            }));

            this.syncRevisionReferenceDocumentInput();
            return true;
        },
        syncRevisionReferenceDocumentInput() {
            const input = this.$refs.revisionReferenceDocumentInput;
            if (!input) return;
            try {
                const transfer = new DataTransfer();
                this.revisionReferenceDocumentFiles.forEach((file) => transfer.items.add(file));
                this.revisionReferenceDocumentSyncing = true;
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (_) {
            } finally {
                this.revisionReferenceDocumentSyncing = false;
            }
        },
        clearReferenceImageState() {
            this.referenceImageDragging = false;
            this.referenceImageUploading = false;
            this.referenceImageUploadFailed = false;
            this.referenceImageDropError = '';
            this.destroyReferenceImageSortable();
            this.clearReferenceImageInput();
        },
        clearReferenceImageInput() {
            if (this.referenceImages.some((image) => image?.url && image.url === this.activeRefImageModalUrl)) {
                this.activeRefImageModalUrl = null;
                this.activeRefImageModalLabel = '';
            }
            this.releaseReferenceImageUrls();
            this.referenceImages = [];
            this.referenceImageFiles = [];

            if (this.$refs.referenceImageInput) {
                this.$refs.referenceImageInput.value = '';
            }
        },
        clearReferenceDocumentState() {
            this.referenceDocumentUploading = false;
            this.referenceDocumentUploadFailed = false;
            this.referenceDocumentDropError = '';
            this.clearReferenceDocumentInput();
        },
        clearReferenceDocumentInput() {
            this.referenceDocuments = [];
            this.referenceDocumentFiles = [];

            if (this.$refs.referenceDocumentInput) {
                this.$refs.referenceDocumentInput.value = '';
            }
        },
        clearRevisionReferenceImageState() {
            this.revisionReferenceImageUploading = false;
            this.revisionReferenceImageUploadFailed = false;
            this.revisionReferenceImageDropError = '';
            this.destroyRevisionReferenceImageSortable();
            this.clearRevisionReferenceImageInput();
        },
        clearRevisionReferenceImageInput() {
            this.releaseRevisionReferenceImageUrls();
            this.revisionReferenceImages = [];
            this.revisionReferenceImageFiles = [];

            if (this.$refs.revisionReferenceImageInput) {
                this.$refs.revisionReferenceImageInput.value = '';
            }
        },
        clearRevisionReferenceDocumentState() {
            this.revisionReferenceDocumentUploading = false;
            this.revisionReferenceDocumentUploadFailed = false;
            this.revisionReferenceDocumentDropError = '';
            this.clearRevisionReferenceDocumentInput();
        },
        clearRevisionReferenceDocumentInput() {
            this.revisionReferenceDocuments = [];
            this.revisionReferenceDocumentFiles = [];

            if (this.$refs.revisionReferenceDocumentInput) {
                this.$refs.revisionReferenceDocumentInput.value = '';
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
        validateReferenceDocumentFile(file) {
            if (!file) return false;

            const allowedExtensions = ['pdf', 'docx', 'xlsx', 'csv'];
            const extension = (file.name.split('.').pop() || '').toLowerCase();

            if (!allowedExtensions.includes(extension)) {
                this.referenceDocumentDropError = 'Gunakan PDF, DOCX, XLSX, atau CSV.';
                return false;
            }

            if (file.size > 10 * 1024 * 1024) {
                this.referenceDocumentDropError = 'Ukuran dokumen maksimal 10 MB.';
                return false;
            }

            this.referenceDocumentDropError = '';
            return true;
        },
        validateRevisionReferenceImageFile(file) {
            if (!file) return false;

            if (!['image/jpeg', 'image/png'].includes(file.type)) {
                this.revisionReferenceImageDropError = 'Gunakan gambar JPG atau PNG.';
                return false;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.revisionReferenceImageDropError = 'Ukuran gambar maksimal 5 MB.';
                return false;
            }

            this.revisionReferenceImageDropError = '';
            return true;
        },
        validateRevisionReferenceDocumentFile(file) {
            if (!file) return false;

            const allowedExtensions = ['pdf', 'docx', 'xlsx', 'csv'];
            const extension = (file.name.split('.').pop() || '').toLowerCase();

            if (!allowedExtensions.includes(extension)) {
                this.revisionReferenceDocumentDropError = 'Gunakan PDF, DOCX, XLSX, atau CSV.';
                return false;
            }

            if (file.size > 10 * 1024 * 1024) {
                this.revisionReferenceDocumentDropError = 'Ukuran dokumen maksimal 10 MB.';
                return false;
            }

            this.revisionReferenceDocumentDropError = '';
            return true;
        },
        dropReferenceImage(event) {
            this.referenceImageDragging = false;
            const files = event.dataTransfer?.files;
            const newFiles = Array.from(files || []);

            const total = this.referenceImageFiles.length + newFiles.length;
            if (total > 5) {
                this.referenceImageDropError = 'Gambar referensi maksimal 5 file.';
                return;
            }

            if (!this.setReferenceImageFiles(files || [])) {
                return;
            }
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

        <div class="flex-1 overflow-y-auto bg-transparent px-4 py-4 space-y-4" x-ref="prompyChatBox" id="prompy-chat-box">
            <div x-show="isSwitchingPrompt" x-transition.opacity x-cloak class="px-2" role="status" aria-live="polite">
                <span class="sr-only">Memuat prompt.</span>
                <div class="inline-flex items-center gap-1.5 rounded-full border border-stone-200/70 bg-white/75 px-3 py-2 text-stone-400 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/75 dark:text-gray-500" aria-hidden="true">
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.2s]"></span>
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.1s]"></span>
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current"></span>
                </div>
            </div>
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

            @if($activePrompt)
                <div class="rounded-lg border border-stone-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Prompt aktif</p>
                            <p class="mt-1 truncate text-[13.5px] font-semibold text-stone-800 dark:text-gray-100">{{ $activePrompt->displayTitle() }}</p>
                            <p class="mt-1 text-[11.5px] text-stone-500 dark:text-gray-400">{{ $activePrompt->platform_label }} · {{ $activePrompt->prompt_type_label }}</p>
                        </div>
                        <button type="button"
                                wire:click="$toggle('showPromptConfiguration')"
                                class="shrink-0 rounded-lg border border-stone-200 px-2.5 py-1.5 text-[11px] font-semibold text-stone-600 transition hover:bg-stone-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                            {{ $showPromptConfiguration ? 'Tutup konfigurasi' : 'Edit konfigurasi' }}
                        </button>
                    </div>
                    @if($activeVersions->count() > 1)
                        <div class="mt-3 flex items-center gap-2 border-t border-stone-100 pt-3 dark:border-gray-800">
                            <label for="prompy-version-select" class="shrink-0 text-[10.5px] font-bold uppercase tracking-wider text-stone-400 dark:text-gray-500">Versi prompt</label>
                            <select id="prompy-version-select"
                                    x-on:change.stop.prevent="$wire.selectPromptVersion($event.target.value)"
                                    class="min-w-0 flex-1 rounded-md border border-stone-200 bg-white px-2.5 py-1.5 text-[12px] font-semibold text-stone-700 shadow-sm outline-none focus:border-ista-primary focus:outline-none focus:ring-1 focus:ring-ista-primary focus-visible:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                @foreach($activeVersions->sortByDesc('version_number') as $version)
                                    <option value="{{ $version->id }}" @selected((int) $activeVersionId === (int) $version->id)>
                                        Versi {{ $version->version_number }} · {{ $version->created_at?->format('H:i') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            @endif

            @if(! $activePrompt || $showPromptConfiguration)
            <form id="prompy-form" wire:submit.prevent="generate" class="chat-form memo-config-panel">
                <div class="border-b border-stone-100 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="mt-1 text-[15px] font-bold text-stone-900 dark:text-gray-100">Konfigurasi Prompt</h2>
                    <p class="mt-1 max-w-[26rem] text-[12px] leading-relaxed text-stone-500 dark:text-gray-400">Isi ide, target, dan referensi visual untuk membuat paket prompt.</p>
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
                                <span class="block truncate text-[12px] font-semibold" x-text="referenceImages.length ? `${referenceImages.length} gambar dipilih — klik untuk tambah` : 'Pilih atau seret gambar'"></span>
                                <span class="mt-0.5 block text-[11px] leading-relaxed opacity-75">Opsional. JPG/PNG, maksimal 5 gambar, masing-masing 5 MB.</span>
                            </span>
                        </button>
                        <div x-show="referenceImages.length > 0" x-cloak x-ref="referenceImageGrid" x-init="$nextTick(() => initReferenceImageSortable($el)); return () => destroyReferenceImageSortable();" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="(image, imgIdx) in referenceImages" :key="image.id">
                                <div data-ref-image-item class="group relative cursor-grab select-none overflow-hidden rounded-lg border border-stone-200 bg-white text-left shadow-sm active:cursor-grabbing dark:border-gray-700 dark:bg-gray-900">
                                    <button type="button" data-no-drag @click.stop="removeReferenceImageAt(imgIdx)" class="absolute right-1 top-1 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-red-600" aria-label="Hapus gambar">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                    <button type="button" @click="openReferenceImageModal(image)" class="block w-full cursor-zoom-in text-left transition hover:opacity-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary" :aria-label="`Buka preview ${image.label}`">
                                        <div class="aspect-[4/3] bg-stone-100 dark:bg-gray-800">
                                            <img :src="image.url" :alt="image.label" class="h-full w-full object-cover">
                                        </div>
                                        <div class="min-w-0 px-2 py-1.5">
                                            <p class="truncate text-[11px] font-bold text-stone-700 dark:text-gray-200" x-text="image.label"></p>
                                            <p class="truncate text-[10.5px] text-stone-400 dark:text-gray-500" x-text="image.name"></p>
                                        </div>
                                    </button>
                                </div>
                            </template>
                        </div>
                        <p x-show="referenceImages.length > 1" x-cloak class="mt-1.5 text-[10.5px] leading-relaxed text-stone-400 dark:text-gray-500">Seret gambar untuk mengubah urutan. Gambar 1 dianalisis lebih dulu.</p>
                        <p x-show="referenceImageDropError" x-cloak class="memo-config-error" x-text="referenceImageDropError"></p>
                        @error('referenceImages') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('referenceImages.*') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-3">
                        <label class="memo-config-label">Dokumen acuan</label>
                        <input
                            x-ref="referenceDocumentInput"
                            type="file"
                            wire:model="referenceDocuments"
                            accept=".pdf,.docx,.xlsx,.csv,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                            multiple
                            class="sr-only"
                            x-on:change="handleReferenceDocumentChange($event)"
                            x-on:livewire-upload-start="referenceDocumentUploading = true; referenceDocumentUploadFailed = false"
                            x-on:livewire-upload-finish="referenceDocumentUploading = false"
                            x-on:livewire-upload-error="referenceDocumentUploading = false; referenceDocumentUploadFailed = true; clearReferenceDocumentInput()"
                        />
                        <button
                            type="button"
                            @click="chooseReferenceDocument()"
                            class="group flex w-full items-center gap-3 rounded-lg border border-dashed px-3 py-2.5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary"
                            :class="referenceDocuments.length > 0 && !referenceDocumentUploading && !referenceDocumentUploadFailed ? 'border-sky-300 bg-sky-50/80 text-sky-800 dark:border-sky-800/60 dark:bg-sky-900/20 dark:text-sky-200' : 'border-stone-200 bg-white text-stone-700 hover:border-ista-primary/40 hover:bg-white dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-amber-400/40'"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-current/15 bg-white/70 dark:bg-gray-950/30">
                                <svg x-show="!referenceDocumentUploading && (referenceDocuments.length === 0 || referenceDocumentUploadFailed)" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 3v5h5M8 13h8M8 17h5" />
                                </svg>
                                <span x-show="referenceDocumentUploading" x-cloak class="h-4 w-4 rounded-full border-2 border-current/50 border-t-transparent animate-spin" aria-hidden="true"></span>
                                <svg x-show="referenceDocuments.length > 0 && !referenceDocumentUploading && !referenceDocumentUploadFailed" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[12px] font-semibold" x-text="referenceDocuments.length ? `${referenceDocuments.length} dokumen dipilih — klik untuk tambah` : 'Pilih dokumen acuan'"></span>
                                <span class="mt-0.5 block text-[11px] leading-relaxed opacity-75">Opsional. PDF/DOCX/XLSX/CSV, maksimal 3 dokumen, masing-masing 10 MB.</span>
                            </span>
                        </button>
                        <div x-show="referenceDocuments.length > 0" x-cloak class="mt-2 rounded-lg border border-sky-200 bg-sky-50/70 p-2 dark:border-sky-900/60 dark:bg-sky-950/20">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold text-sky-800 dark:text-sky-200">Dokumen ini dipakai sebagai konteks prompt.</p>
                                <button type="button"
                                        @click="clearReferenceDocumentState(); $wire.clearReferenceDocuments()"
                                        class="shrink-0 rounded-md px-2 py-1 text-[10.5px] font-semibold text-sky-700 hover:bg-sky-100 dark:text-sky-200 dark:hover:bg-sky-900/50">
                                    Hapus
                                </button>
                            </div>
                            <div class="mt-2 flex flex-col gap-1">
                                <template x-for="document in referenceDocuments" :key="document.label">
                                    <div class="flex min-w-0 items-center gap-2 rounded-md border border-sky-200 bg-white px-2 py-1.5 text-left dark:border-sky-900/70 dark:bg-gray-900">
                                        <span class="shrink-0 text-[10px] font-bold text-sky-700 dark:text-sky-200" x-text="document.label"></span>
                                        <span class="truncate text-[10.5px] text-sky-700/80 dark:text-sky-300/80" x-text="document.name"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <p x-show="referenceDocumentDropError" x-cloak class="memo-config-error" x-text="referenceDocumentDropError"></p>
                        @error('referenceDocuments') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('referenceDocuments.*') <p class="memo-config-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </form>
            @elseif($activePrompt)
                @foreach($promptChatMessages as $index => $msg)
                    @php
                        $isUserMessage = ($msg['role'] ?? '') === 'user';
                    @endphp
                    <div wire:key="prompy-msg-{{ $index }}" class="flex {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                        <div class="w-full flex items-start gap-2.5 {{ $isUserMessage ? 'flex-row-reverse' : '' }}">
                            <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center {{ $isUserMessage ? 'bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black' : 'bg-white border border-stone-200 shadow-sm p-1' }}">
                                @if($isUserMessage)
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
                                    <span class="text-[10px] text-gray-400 dark:text-[#64748B]">{{ $msg['timestamp'] ?? '' }}</span>
                                </div>

                                <div class="{{ $isUserMessage
                                    ? 'bg-ista-primary text-white rounded-lg rounded-br-sm px-4 py-3'
                                    : 'bg-white/95 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/80 dark:border-gray-800 text-stone-700 dark:text-gray-100 rounded-lg rounded-bl-sm px-4 py-3 shadow-sm' }}">
                                    <p class="text-[14px] leading-relaxed whitespace-pre-wrap">{{ $msg['content'] ?? '' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                <template x-if="prompyRevisionText">
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
                                    <p class="text-[14px] leading-relaxed whitespace-pre-wrap" x-text="prompyRevisionText"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="flex justify-start" wire:loading.flex wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision,revisePrompt,sendPromptChat" wire:key="prompy-loading-bubble">
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
                                <span class="ista-loading-shimmer text-[12px] font-medium whitespace-nowrap">Menyusun paket prompt...</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="chat-composer-safe flex-shrink-0 px-4 pt-2 bg-transparent w-full">
            @if(! $activePrompt || $showPromptConfiguration)
                <div class="rounded-lg border border-stone-200 bg-white p-2 shadow-[0_-10px_30px_-24px_rgba(28,25,23,0.45)] dark:border-gray-800 dark:bg-gray-900">
                    <button type="submit"
                            form="prompy-form"
                            wire:loading.attr="disabled"
                            wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision,referenceImages,referenceDocuments"
                            :disabled="referenceImageUploading || referenceDocumentUploading"
                            @click="showPrompyPreviewPanel()"
                            class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-ista-primary px-4 text-[13px] font-semibold text-white shadow-sm transition hover:bg-ista-dark active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision">{{ $activePrompt ? 'Buat ulang dari konfigurasi' : 'Buat Prompt' }}</span>
                        <span wire:loading.inline-flex wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision" class="items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                            <span>Menyusun prompt...</span>
                        </span>
                    </button>
                </div>
            @elseif($activePrompt)
                @if($activeReferenceImages->isNotEmpty())
                    <div
                        wire:key="prompy-active-reference-images-{{ $activePrompt->id }}-{{ $activeVersion?->id ?? 'none' }}-{{ md5($activeReferenceImages->pluck('url')->implode('|')) }}"
                        class="mb-2 flex items-center gap-2"
                        x-init="excludedActiveRefImages = []">
                        @foreach($activeReferenceImages as $imgIdx => $image)
                            <div
                                wire:key="prompy-active-reference-image-{{ $activePrompt->id }}-{{ $activeVersion?->id ?? 'none' }}-{{ $imgIdx }}"
                                x-show="!excludedActiveRefImages.includes({{ $imgIdx }})"
                                x-cloak
                                class="relative group">
                                <button type="button" @click="activeRefImageModalUrl = @js($image['url']); activeRefImageModalLabel = @js($image['label'])" class="block h-10 w-10 overflow-hidden rounded-md border border-stone-200 shadow-sm transition hover:ring-2 hover:ring-ista-primary/40 dark:border-gray-700">
                                    <img src="{{ $image['url'] }}" alt="{{ $image['label'] }}" class="h-full w-full object-cover" />
                                </button>
                                <button type="button" @click="excludedActiveRefImages.push({{ $imgIdx }})" class="absolute -right-1 -top-1 z-10 hidden h-4 w-4 items-center justify-center rounded-full bg-black/70 text-white group-hover:flex hover:bg-red-600" aria-label="Hapus acuan">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <form @submit.prevent="submitPrompyRevision($wire, $refs.prompyInput)" class="chat-form relative rounded-xl shadow-sm bg-white dark:bg-gray-800 border border-stone-200/60 dark:border-gray-700 transition-colors">
                    <div class="px-3 pb-3 pt-3 w-full">
                        <input
                            x-ref="revisionReferenceImageInput"
                            type="file"
                            wire:model="revisionReferenceImages"
                            accept="image/jpeg,image/png"
                            multiple
                            class="sr-only"
                            x-on:change="handleRevisionReferenceImageChange($event)"
                            x-on:livewire-upload-start="revisionReferenceImageUploading = true; revisionReferenceImageUploadFailed = false"
                            x-on:livewire-upload-finish="revisionReferenceImageUploading = false"
                            x-on:livewire-upload-error="revisionReferenceImageUploading = false; revisionReferenceImageUploadFailed = true; clearRevisionReferenceImageInput()"
                        />
                        <input
                            x-ref="revisionReferenceDocumentInput"
                            type="file"
                            wire:model="revisionReferenceDocuments"
                            accept=".pdf,.docx,.xlsx,.csv,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                            multiple
                            class="sr-only"
                            x-on:change="handleRevisionReferenceDocumentChange($event)"
                            x-on:livewire-upload-start="revisionReferenceDocumentUploading = true; revisionReferenceDocumentUploadFailed = false"
                            x-on:livewire-upload-finish="revisionReferenceDocumentUploading = false"
                            x-on:livewire-upload-error="revisionReferenceDocumentUploading = false; revisionReferenceDocumentUploadFailed = true; clearRevisionReferenceDocumentInput()"
                        />
                        <textarea
                            wire:model="revisionInstruction"
                            x-ref="prompyInput"
                            @keydown.enter="if(!$event.shiftKey) { $event.preventDefault(); submitPrompyRevision($wire, $refs.prompyInput); }"
                            placeholder="Tulis pesan untuk membahas prompt ini..."
                            aria-label="Tulis pesan untuk membahas prompt ini"
                            rows="1"
                            maxlength="{{ \App\Services\Prompts\PromptStudioService::REVISION_INSTRUCTION_MAX_LENGTH }}"
                            class="chat-input w-full max-h-[120px] min-h-[44px] bg-transparent border-none focus:ring-0 focus:outline-none focus:border-transparent focus-visible:ring-0 focus-visible:outline-none resize-none text-[14px] text-stone-800 dark:text-[#F8FAFC] placeholder-[#94A3B8] dark:placeholder-[#64748B] px-2 py-[10px] hover:bg-transparent focus:bg-transparent"
                            style="outline: none !important; box-shadow: none !important;"
                            x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                        ></textarea>
                        @error('revisionInstruction') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('revisionReferenceImages') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('revisionReferenceImages.*') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('revisionReferenceDocuments') <p class="memo-config-error">{{ $message }}</p> @enderror
                        @error('revisionReferenceDocuments.*') <p class="memo-config-error">{{ $message }}</p> @enderror

                        <div x-show="revisionReferenceImages.length > 0" x-cloak class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/70 p-2 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold text-emerald-800 dark:text-emerald-200" x-text="`${revisionReferenceImages.length} gambar baru untuk versi berikutnya`"></p>
                                <button type="button"
                                        @click="clearRevisionReferenceImageState(); $wire.clearRevisionReferenceImages()"
                                        class="shrink-0 rounded-md px-2 py-1 text-[10.5px] font-semibold text-emerald-700 hover:bg-emerald-100 dark:text-emerald-200 dark:hover:bg-emerald-900/50">
                                    Hapus
                                </button>
                            </div>
                            <div x-ref="revisionReferenceImageGrid" x-init="$nextTick(() => initRevisionReferenceImageSortable($el)); return () => destroyRevisionReferenceImageSortable();" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <template x-for="(image, imgIdx) in revisionReferenceImages" :key="image.id">
                                    <div data-ref-image-item class="group relative cursor-grab select-none overflow-hidden rounded-md border border-emerald-200 bg-white text-left active:cursor-grabbing dark:border-emerald-900/70 dark:bg-gray-900">
                                        <button type="button" data-no-drag @click="removeRevisionReferenceImageAt(imgIdx)" class="absolute right-1 top-1 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-red-600" aria-label="Hapus gambar">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                        <div class="aspect-[4/3] bg-emerald-100/70 dark:bg-gray-800">
                                            <img :src="image.url" :alt="image.label" class="h-full w-full object-cover">
                                        </div>
                                        <div class="min-w-0 px-2 py-1">
                                            <p class="truncate text-[10.5px] font-bold text-emerald-800 dark:text-emerald-200" x-text="image.label"></p>
                                            <p class="truncate text-[10px] text-emerald-700/70 dark:text-emerald-300/70" x-text="image.name"></p>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <p x-show="revisionReferenceImages.length > 1" x-cloak class="mt-1.5 text-[10.5px] leading-relaxed text-emerald-700/80 dark:text-emerald-300/70">Seret gambar untuk mengubah urutan. Gambar 1 dianalisis lebih dulu.</p>
                        <p x-show="revisionReferenceImageDropError" x-cloak class="memo-config-error" x-text="revisionReferenceImageDropError"></p>

                        <div x-show="revisionReferenceDocuments.length > 0" x-cloak class="mt-2 rounded-lg border border-sky-200 bg-sky-50/70 p-2 dark:border-sky-900/60 dark:bg-sky-950/20">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold text-sky-800 dark:text-sky-200" x-text="`${revisionReferenceDocuments.length} dokumen baru untuk versi berikutnya`"></p>
                                <button type="button"
                                        @click="clearRevisionReferenceDocumentState(); $wire.clearRevisionReferenceDocuments()"
                                        class="shrink-0 rounded-md px-2 py-1 text-[10.5px] font-semibold text-sky-700 hover:bg-sky-100 dark:text-sky-200 dark:hover:bg-sky-900/50">
                                    Hapus
                                </button>
                            </div>
                            <div class="mt-2 flex flex-col gap-1">
                                <template x-for="document in revisionReferenceDocuments" :key="document.label">
                                    <div class="flex min-w-0 items-center gap-2 rounded-md border border-sky-200 bg-white px-2 py-1.5 text-left dark:border-sky-900/70 dark:bg-gray-900">
                                        <span class="shrink-0 text-[10px] font-bold text-sky-700 dark:text-sky-200" x-text="document.label"></span>
                                        <span class="truncate text-[10.5px] text-sky-700/80 dark:text-sky-300/80" x-text="document.name"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <p x-show="revisionReferenceDocumentDropError" x-cloak class="memo-config-error" x-text="revisionReferenceDocumentDropError"></p>

                        <div class="mt-2 flex items-center justify-between gap-2">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <button type="button"
                                        @click="chooseRevisionReferenceImage()"
                                        wire:loading.attr="disabled"
                                        wire:target="revisionReferenceImages,sendPromptChat"
                                        :disabled="prompyRevisionLoading || revisionReferenceImageUploading || revisionReferenceDocumentUploading || $wire.isGenerating"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-full border border-stone-200 px-2.5 text-[11px] font-semibold text-stone-500 transition hover:border-ista-primary/40 hover:text-ista-primary disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-400 dark:hover:border-amber-400/50 dark:hover:text-amber-200">
                                    <svg x-show="!revisionReferenceImageUploading" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16.5 6.5l-7.8 7.8a3 3 0 104.2 4.2l8.1-8.1a5 5 0 10-7.1-7.1L5.8 11.4" />
                                    </svg>
                                    <span x-show="revisionReferenceImageUploading" x-cloak class="h-3.5 w-3.5 rounded-full border-2 border-current/50 border-t-transparent animate-spin" aria-hidden="true"></span>
                                    <span x-text="revisionReferenceImages.length ? 'Tambah gambar' : 'Lampirkan gambar'"></span>
                                </button>
                                <button type="button"
                                        @click="chooseRevisionReferenceDocument()"
                                        wire:loading.attr="disabled"
                                        wire:target="revisionReferenceDocuments,sendPromptChat"
                                        :disabled="prompyRevisionLoading || revisionReferenceImageUploading || revisionReferenceDocumentUploading || $wire.isGenerating"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-full border border-stone-200 px-2.5 text-[11px] font-semibold text-stone-500 transition hover:border-ista-primary/40 hover:text-ista-primary disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-400 dark:hover:border-amber-400/50 dark:hover:text-amber-200">
                                    <svg x-show="!revisionReferenceDocumentUploading" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 3v5h5M8 13h8M8 17h5" />
                                    </svg>
                                    <span x-show="revisionReferenceDocumentUploading" x-cloak class="h-3.5 w-3.5 rounded-full border-2 border-current/50 border-t-transparent animate-spin" aria-hidden="true"></span>
                                    <span x-text="revisionReferenceDocuments.length ? 'Tambah file' : 'Lampirkan file'"></span>
                                </button>
                            </div>
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="sendPromptChat,revisionReferenceImages,revisionReferenceDocuments"
                                    :disabled="prompyRevisionLoading || revisionReferenceImageUploading || revisionReferenceDocumentUploading || $wire.isGenerating"
                                    class="bg-ista-primary hover:bg-ista-dark dark:bg-ista-primary dark:hover:bg-ista-dark disabled:opacity-50 disabled:cursor-not-allowed rounded-full transition-all duration-300 h-[32px] w-[32px] flex items-center justify-center group"
                                    :aria-label="prompyRevisionLoading ? 'Mengirim pesan prompt' : 'Kirim pesan prompt'">
                                <span x-show="prompyRevisionLoading" x-cloak class="h-[17px] w-[17px] rounded-full border-2 border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                                <img x-show="!prompyRevisionLoading" src="{{ asset('images/icons/send-light.svg') }}" alt="" class="h-[17px] w-[17px] dark:hidden brightness-0 invert" />
                                <img x-show="!prompyRevisionLoading" src="{{ asset('images/icons/send-dark.svg') }}" alt="" class="h-[17px] w-[17px] hidden dark:block brightness-0 invert" />
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
            <div wire:loading.flex wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision,revisePrompt" class="min-h-[420px] items-center justify-center px-6 text-center">
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

            <div wire:loading.remove wire:target="generate,generateConfiguredPrompt,generateConfiguredRevision,revisePrompt" class="space-y-4">
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

    {{-- Modal full-size reference image --}}
    <template x-teleport="body">
        <div x-show="activeRefImageModalUrl" x-cloak x-transition.opacity
             @click="activeRefImageModalUrl = null"
             @keydown.escape.window="activeRefImageModalUrl = null"
             class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
            <div class="relative max-h-[90vh] max-w-[90vw]" @click.stop>
                <button type="button" @click="activeRefImageModalUrl = null"
                        class="absolute -right-2 -top-2 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-white shadow-lg text-stone-700 hover:bg-red-50 hover:text-red-600 transition"
                        aria-label="Tutup">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <img :src="activeRefImageModalUrl" :alt="activeRefImageModalLabel" class="max-h-[85vh] max-w-full rounded-lg object-contain shadow-2xl" />
                <p class="mt-2 text-center text-[12px] font-semibold text-white/80" x-text="activeRefImageModalLabel"></p>
            </div>
        </div>
    </template>
</div>
