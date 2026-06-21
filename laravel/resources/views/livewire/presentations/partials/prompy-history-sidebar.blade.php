@php
    $promptHistoryNow = now('Asia/Jakarta');
    $promptHistoryTodayStart = $promptHistoryNow->copy()->startOfDay();
    $promptHistorySevenDayStart = $promptHistoryTodayStart->copy()->subDays(7);
    $promptHistoryThirtyDayStart = $promptHistoryTodayStart->copy()->subDays(30);
    $promptCreatedAt = fn ($prompt) => $prompt->created_at?->copy()->timezone('Asia/Jakarta');

    $todayPrompts = $prompts->filter(function ($prompt) use ($promptCreatedAt, $promptHistoryTodayStart) {
        $createdAt = $promptCreatedAt($prompt);

        return $createdAt && $createdAt->greaterThanOrEqualTo($promptHistoryTodayStart);
    });
    $sevenDayPrompts = $prompts->filter(function ($prompt) use ($promptCreatedAt, $promptHistoryTodayStart, $promptHistorySevenDayStart) {
        $createdAt = $promptCreatedAt($prompt);

        return $createdAt && $createdAt->lessThan($promptHistoryTodayStart) && $createdAt->greaterThanOrEqualTo($promptHistorySevenDayStart);
    });
    $thirtyDayPrompts = $prompts->filter(function ($prompt) use ($promptCreatedAt, $promptHistorySevenDayStart, $promptHistoryThirtyDayStart) {
        $createdAt = $promptCreatedAt($prompt);

        return $createdAt && $createdAt->lessThan($promptHistorySevenDayStart) && $createdAt->greaterThanOrEqualTo($promptHistoryThirtyDayStart);
    });
    $olderPrompts = $prompts->filter(function ($prompt) use ($promptCreatedAt, $promptHistoryThirtyDayStart) {
        $createdAt = $promptCreatedAt($prompt);

        return $createdAt && $createdAt->lessThan($promptHistoryThirtyDayStart);
    });
    $promptGroups = [
        ['key' => 'today', 'label' => 'Hari Ini', 'prompts' => $todayPrompts, 'collapsible' => false],
        ['key' => 'seven', 'label' => '7 Hari Terakhir', 'prompts' => $sevenDayPrompts, 'collapsible' => true],
        ['key' => 'thirty', 'label' => '30 Hari Terakhir', 'prompts' => $thirtyDayPrompts, 'collapsible' => true],
        ['key' => 'older', 'label' => 'Lebih Lama', 'prompts' => $olderPrompts, 'collapsible' => true],
    ];
    $activePromptHistoryId = $activePromptId ? (int) $activePromptId : null;
    $openPromptSections = collect($promptGroups)
        ->filter(fn (array $group) => $group['collapsible'])
        ->mapWithKeys(fn (array $group) => [
            $group['key'] => $activePromptHistoryId
                ? $group['prompts']->contains(fn ($prompt) => (int) $prompt->id === $activePromptHistoryId)
                : false,
        ])
        ->all();
    $foldedPromptSectionKeys = collect($promptGroups)
        ->filter(fn (array $group) => $group['collapsible'] && $group['prompts']->isNotEmpty())
        ->pluck('key')
        ->values()
        ->all();
    $promptTitles = $prompts->map(fn ($prompt) => $prompt->displayTitle())->values()->all();
@endphp

<aside
    x-data="{
        openSections: @js($openPromptSections),
        sectionKeys: @js($foldedPromptSectionKeys),
        search: '',
        titles: @js($promptTitles),
        normalizedSearch() { return String(this.search || '').trim().toLowerCase(); },
        isSearching() { return this.normalizedSearch().length > 0; },
        isVisible(title) {
            const search = this.normalizedSearch();
            return ! search || String(title || '').toLowerCase().includes(search);
        },
        hasResults() {
            const search = this.normalizedSearch();
            return ! search || this.titles.some((title) => String(title || '').toLowerCase().includes(search));
        },
        isOpen(section) { return this.isSearching() || Boolean(this.openSections?.[section]); },
        toggle(section) { this.openSections = { ...this.openSections, [section]: !this.openSections?.[section] }; },
        allOpen() { return this.sectionKeys.length > 0 && this.sectionKeys.every((section) => Boolean(this.openSections?.[section])); },
        toggleAll() {
            const shouldOpen = ! this.allOpen();
            const next = { ...this.openSections };
            this.sectionKeys.forEach((section) => { next[section] = shouldOpen; });
            this.openSections = next;
        },
    }"
    :class="[
        showPresentationSidebar ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-full pointer-events-none',
        isMobile ? 'fixed left-0 top-0 h-full w-[288px] shadow-2xl border-r border-stone-200/60 dark:border-[#1E293B]' : (showPresentationSidebar ? 'relative w-[288px] border-r border-stone-200/60 dark:border-[#1E293B]' : 'relative w-0 border-r border-transparent')
    ]"
    @click.stop
    class="z-50 flex-shrink-0 overflow-hidden bg-white dark:bg-gray-900 flex flex-col transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
>
    <div class="flex items-center justify-between px-4 pb-2 pt-3">
        <a href="{{ route('dashboard') }}" @click="showPresentationSidebar = false" class="inline-flex items-center px-1 py-2.5 font-medium text-[13px] text-gray-700 dark:text-gray-200 hover:text-amber-800 dark:hover:text-amber-300 transition-colors duration-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
            Kembali ke Beranda
        </a>
        <button type="button" x-show="isMobile" @click="showPresentationSidebar = false" class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors" aria-label="Tutup sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="p-4 pt-2 pb-5">
        <button type="button"
                wire:click="startNewPrompt"
                @click="showPresentationConfigPanel()"
                class="w-full flex items-center justify-start px-4 py-2.5 rounded-lg border border-stone-200/60 dark:border-[#334155] dark:bg-transparent bg-white hover:bg-gray-50 dark:hover:bg-white/5 font-medium text-[13px] text-gray-700 dark:text-gray-200 transition-all duration-200 shadow-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-[#64748B] dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14m-7-7h14" />
            </svg>
            Prompt Baru
        </button>
    </div>

    @if ($prompts->isNotEmpty())
        <div class="px-4 pb-4">
            <div class="relative">
                <label for="prompy-history-search" class="sr-only">Cari prompt</label>
                <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#94A3B8] dark:text-[#64748B]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
                </svg>
                <input
                    id="prompy-history-search"
                    type="search"
                    x-model.debounce.150ms="search"
                    placeholder="Cari prompt..."
                    class="h-9 w-full rounded-lg border border-stone-200/70 bg-white pl-9 pr-8 text-[12.5px] text-gray-700 outline-none placeholder:text-[#94A3B8] focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-[#334155] dark:bg-transparent dark:text-gray-100 dark:placeholder:text-[#64748B]"
                >
            </div>

            <div class="mt-2 flex items-center justify-between px-1">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-[#94A3B8] dark:text-[#64748B]">Riwayat Prompt</span>
                @if (! empty($foldedPromptSectionKeys))
                    <button type="button" @click="toggleAll()" class="rounded-md px-2 py-1 text-[11px] font-semibold text-ista-primary transition-colors hover:bg-ista-primary/10 dark:text-amber-200 dark:hover:bg-amber-300/10" x-text="allOpen() ? 'Ringkas' : 'Lihat semua'">Lihat semua</button>
                @endif
            </div>
        </div>
    @endif

    <div class="flex-1 overflow-y-auto overflow-x-hidden px-4">
        @if ($prompts->isEmpty())
            <div class="px-3 py-6 text-center">
                <p class="text-[12px] text-stone-400 dark:text-gray-500">Belum ada prompt</p>
                <button type="button" wire:click="startNewPrompt" class="mt-3 rounded-lg bg-ista-primary px-3 py-2 text-[12px] font-semibold text-white hover:bg-ista-dark">Buat prompt pertama</button>
            </div>
        @else
            @foreach ($promptGroups as $group)
                @php
                    $groupKey = $group['key'];
                    $groupLabel = $group['label'];
                    $groupPrompts = $group['prompts'];
                    $isCollapsible = (bool) $group['collapsible'];
                @endphp

                @continue($isCollapsible && $groupPrompts->isEmpty())

                <div class="mb-6" data-prompy-history-section="{{ $groupKey }}">
                    @if ($isCollapsible)
                        <button type="button" @click="toggle('{{ $groupKey }}')" :aria-expanded="isOpen('{{ $groupKey }}') ? 'true' : 'false'" aria-controls="prompy-history-section-{{ $groupKey }}" class="flex w-full items-center justify-between text-left">
                            <span class="truncate text-[11.3px] font-bold uppercase tracking-wider text-[#64748B] dark:text-[#94A3B8]">{{ $groupLabel }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" :class="isOpen('{{ $groupKey }}') ? 'rotate-180' : ''" class="h-3 w-3 text-[#64748B] dark:text-[#94A3B8] transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <ul id="prompy-history-section-{{ $groupKey }}" x-show="isOpen('{{ $groupKey }}')" class="mt-2 space-y-1" style="{{ ($openPromptSections[$groupKey] ?? false) ? '' : 'display: none;' }}">
                    @else
                        <h3 class="text-[11.6px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2">{{ $groupLabel }}</h3>
                        <ul id="prompy-history-section-{{ $groupKey }}" class="space-y-1">
                    @endif

                    @forelse ($groupPrompts as $prompt)
                        @php $promptDisplayTitle = $prompt->displayTitle(); @endphp
                        <li class="group relative" wire:key="prompy-sidebar-{{ $groupKey }}-{{ $prompt->id }}" x-show="isVisible(@js($promptDisplayTitle))">
                            <button type="button"
                                    wire:click="selectPrompt({{ $prompt->id }})"
                                    @click="showPresentationPreviewPanel()"
                                    aria-current="{{ (int) $activePromptHistoryId === (int) $prompt->id ? 'page' : 'false' }}"
                                    class="chat-history-item items-start gap-2.5 py-2.5 pr-9 {{ (int) $activePromptHistoryId === (int) $prompt->id ? 'is-active' : '' }}">
                                <span class="mt-0.5 flex h-4 w-4 flex-shrink-0 items-center justify-center text-stone-400 dark:text-gray-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h8M8 11h8M8 15h5M5 4h14v16H5z" />
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13.2px] font-medium" title="{{ $promptDisplayTitle }}">{{ $promptDisplayTitle }}</span>
                                    <span class="mt-1 block truncate text-[10.5px] text-stone-400 dark:text-gray-500">
                                        {{ $prompt->platform_label }} · {{ $prompt->prompt_type_label }}
                                    </span>
                                </span>
                            </button>
                            <button type="button"
                                    wire:click="deletePrompt({{ $prompt->id }})"
                                    wire:confirm="Hapus prompt ini?"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-md opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100 focus:opacity-100 focus-visible:opacity-100 hover:bg-red-100 dark:hover:bg-red-500/20 text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-all duration-200"
                                    title="Hapus prompt" aria-label="Hapus prompt {{ $promptDisplayTitle }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </li>
                    @empty
                        @if ($groupKey === 'today')
                            <li class="rounded-lg border border-dashed border-stone-200/70 px-3 py-3 text-[12px] leading-relaxed text-stone-400 dark:border-gray-800 dark:text-gray-500">
                                Belum ada prompt hari ini.
                            </li>
                        @endif
                    @endforelse
                    </ul>
                </div>
            @endforeach

            <div x-show="isSearching() && !hasResults()" x-transition.opacity class="mb-6 rounded-lg border border-dashed border-stone-200/70 px-3 py-3 text-[12px] leading-relaxed text-stone-400 dark:border-gray-800 dark:text-gray-500" style="display: none;">
                Tidak ada prompt yang cocok.
            </div>
        @endif
    </div>

    <div class="px-4 py-6 text-sm flex flex-col gap-2">
        <a href="{{ route('profile', ['from' => 'presentation']) }}" class="flex items-center gap-3 text-gray-700 dark:text-[#F8FAFC] hover:opacity-80 transition-opacity">
            <div class="h-8 w-8 rounded bg-ista-primary flex items-center justify-center text-white shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </div>
            <div>
                <h4 class="text-[13.1px] font-medium leading-tight">Pengaturan Akun</h4>
                <p class="text-[11.3px] text-gray-500 dark:text-gray-400">Kelola profil dan preferensi</p>
            </div>
        </a>
    </div>
</aside>
