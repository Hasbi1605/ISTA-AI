{{-- Tab Toggle: Chat / Memo / Presentasi (Presentasi di belakang feature flag, epic #218) --}}
<div class="inline-flex items-center rounded-full border border-stone-200/80 bg-white/80 p-1 shadow-sm backdrop-blur-sm dark:border-gray-700 dark:bg-gray-800/80" role="tablist" aria-label="Pilih mode chat, memo, atau presentasi">
    <button
        type="button"
        @click="$dispatch('chat-tab-switch', { tab: 'chat' })"
        role="tab"
        id="chat-mode-tab"
        aria-controls="chat-mode-panel"
        :aria-selected="activeTab === 'chat' ? 'true' : 'false'"
        :tabindex="activeTab === 'chat' ? 0 : -1"
        aria-label="Buka tab chat"
        :class="activeTab === 'chat'
            ? 'bg-ista-primary text-white shadow-sm'
            : 'text-stone-500 hover:text-stone-700 dark:text-gray-400 dark:hover:text-gray-200'"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-semibold transition-all duration-200 sm:px-4"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        <span class="hidden sm:inline">Chat</span>
    </button>
    <button
        type="button"
        @click="$dispatch('chat-tab-switch', { tab: 'memo' })"
        role="tab"
        id="memo-mode-tab"
        aria-controls="memo-mode-panel"
        :aria-selected="activeTab === 'memo' ? 'true' : 'false'"
        :tabindex="activeTab === 'memo' ? 0 : -1"
        aria-label="Buka tab memo"
        :class="activeTab === 'memo'
            ? 'bg-ista-primary text-white shadow-sm'
            : 'text-stone-500 hover:text-stone-700 dark:text-gray-400 dark:hover:text-gray-200'"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-semibold transition-all duration-200 sm:px-4"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <span class="hidden sm:inline">Memo</span>
    </button>
    @if(config('features.presentation'))
    <button
        type="button"
        @click="$dispatch('chat-tab-switch', { tab: 'presentation' })"
        role="tab"
        id="presentation-mode-tab"
        aria-controls="presentation-mode-panel"
        :aria-selected="activeTab === 'presentation' ? 'true' : 'false'"
        :tabindex="activeTab === 'presentation' ? 0 : -1"
        aria-label="Buka tab presentasi"
        :class="activeTab === 'presentation'
            ? 'bg-ista-primary text-white shadow-sm'
            : 'text-stone-500 hover:text-stone-700 dark:text-gray-400 dark:hover:text-gray-200'"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-semibold transition-all duration-200 sm:px-4"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3M12 9v3" />
        </svg>
        <span class="hidden sm:inline">Presentasi</span>
    </button>
    @endif
</div>
