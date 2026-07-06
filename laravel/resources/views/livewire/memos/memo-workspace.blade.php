<div
    x-data="memoWorkspace"
    data-memo-force-save-base-url="{{ url('/chat/memos') }}"
    x-on:memo-document-ready.window="collapseMemoSidebarForDocument()"
    x-on:memo-loading.window="isSwitchingMemo = true"
    x-on:memo-loaded.window="isSwitchingMemo = false"
    class="chat-viewport flex w-full h-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900"
    style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
>
    @if ($memoStatusMessage)
        <div class="fixed left-1/2 top-4 z-[80] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 shadow-xl dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100" role="status" aria-live="polite">
            {{ $memoStatusMessage }}
        </div>
    @endif

    {{-- LEFT SIDEBAR: Memo History --}}
    @include('livewire.memos.partials.memo-history-sidebar')

    {{-- CENTER: AI Chat Panel --}}
    @include('livewire.memos.partials.memo-chat')

    {{-- RIGHT: Document Panel --}}
    @include('livewire.memos.partials.memo-preview-panel')

    <div
        x-show="isMobile && showMemoSidebar"
        x-transition.opacity
        @click="showMemoSidebar = false"
        class="fixed inset-0 bg-black/50 z-40"
        style="display:none;"
    ></div>

</div>
