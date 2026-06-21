<div
    x-data="presentationWorkspace"
    x-on:presentation-document-ready.window="collapsePresentationSidebarForDocument()"
    class="chat-viewport flex w-full h-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900"
    style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
>
    @if($hasInProgress)
        <div wire:poll.5s class="hidden" aria-hidden="true"></div>
    @endif

    @if($statusMessage)
        <div
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => { show = false; $wire.set('statusMessage', null); }, 5000)"
            class="fixed left-1/2 top-4 z-[80] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 pl-4 pr-10 py-3 text-sm font-semibold text-emerald-800 shadow-xl dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100"
            role="status"
            aria-live="polite"
        >
            <span>{{ $statusMessage }}</span>
            <button
                type="button"
                @click="show = false; $wire.set('statusMessage', null)"
                class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-emerald-600 hover:text-emerald-950 dark:text-emerald-300 dark:hover:text-white transition-colors"
                aria-label="Tutup pesan"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    @endif

    @if($subMode !== 'prompy')
        @include('livewire.presentations.partials.presentation-history-sidebar')
    @endif

    @if($subMode === 'prompy')
        <livewire:presentations.prompy-studio wire:key="prompy-studio-shell" />
    @else
        @include('livewire.presentations.partials.presentation-config-panel')
        @include('livewire.presentations.partials.presentation-preview-panel')
    @endif

    <div
        x-show="isMobile && showPresentationSidebar"
        x-transition.opacity
        @click="showPresentationSidebar = false"
        class="fixed inset-0 bg-black/50 z-40"
        style="display:none;"
    ></div>
</div>
