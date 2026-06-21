<div
    x-data="presentationWorkspace"
    class="chat-viewport flex w-full h-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900"
    style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
>
    <livewire:presentations.prompy-studio wire:key="prompy-studio-shell" />

    <div
        x-show="isMobile && showPresentationSidebar"
        x-transition.opacity
        @click="showPresentationSidebar = false"
        class="fixed inset-0 bg-black/50 z-40"
        style="display:none;"
    ></div>
</div>
