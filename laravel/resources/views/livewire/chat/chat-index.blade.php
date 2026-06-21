<div x-data="chatLayout({ activeTab: $wire.entangle('tab').live, presentationEnabled: @js($presentationEnabled) })"
     x-on:dragenter.window="onDragEnter($event)"
     x-on:dragover.window="onDragOver($event)"
     x-on:dragleave.window="onDragLeave($event)"
     x-on:drop.window="onDropFile($event)"
     x-on:dragend.window="resetDraggingFile()"
     x-on:blur.window="resetDraggingFile()"
     x-on:keydown.escape.window="resetDraggingFile()"
     x-on:open-sidebar-right.window="showRightSidebar = true"
     x-on:chat-tab-switch.window="setTab($event.detail?.tab)"
     x-on:conversation-loading.window="isSwitchingConversation = true"
     x-on:conversation-loaded.window="isSwitchingConversation = false"
     class="chat-viewport flex w-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900" style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
>
    @php
        $uiIcons = [
            'historyLight' => asset('images/icons/history-light.svg'),
            'historyDark' => asset('images/icons/history-dark.svg'),
            'collapseLeftLight' => asset('images/icons/collapse-left-light.svg'),
            'collapseLeftDark' => asset('images/icons/collapse-left-dark.svg'),
            'collapseRightLight' => asset('images/icons/collapse-right-light.svg'),
            'collapseRightDark' => asset('images/icons/collapse-right-dark.svg'),
            'searchLight' => asset('images/icons/search-light.svg'),
            'searchDark' => asset('images/icons/search-dark.svg'),
            'uploadLight' => asset('images/icons/upload-light.svg'),
            'uploadDark' => asset('images/icons/upload-dark.svg'),
            'sendLight' => asset('images/icons/send-light.svg'),
            'sendDark' => asset('images/icons/send-dark.svg'),
        ];
    @endphp

    {{-- ===== CHAT TAB CONTENT ===== --}}
    <div x-show="activeTab === 'chat'" class="flex w-full h-full overflow-hidden">
        @if(! empty($pendingConversationIds))
            {{-- Polling 3s: jalur utama untuk mendeteksi assistant message yang sudah selesai dipersist.
                 GenerateChatResponse tidak dispatch event setelah selesai, sehingga polling tetap
                 dibutuhkan sebagai satu-satunya mekanisme refresh. --}}
            <div wire:poll.3s="refreshPendingChatState" class="hidden" aria-hidden="true"></div>
        @endif

        <!-- LEFT SIDEBAR: Chat History -->
        @include('livewire.chat.partials.chat-left-sidebar')

        <!-- CENTER MAIN: Chat Area -->
        <main class="flex-1 flex flex-col relative w-full h-full bg-transparent z-0 overflow-hidden min-w-0">

                <!-- Header for Chat Space -->
                <div class="h-[61px] flex-shrink-0 flex items-center justify-between px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
                    <!-- Left toggler and title -->
                    <div class="flex items-center gap-2 sm:gap-4">
                        <button type="button" @click="showLeftSidebar = !showLeftSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0" :aria-label="showLeftSidebar ? 'Sembunyikan sidebar chat' : 'Tampilkan sidebar chat'">
                            <img src="{{ $uiIcons['collapseLeftLight'] }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                            <img src="{{ $uiIcons['collapseLeftDark'] }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                        </button>
                        <a href="{{ route('chat', ['tab' => $tab]) }}"
                           @click="window.chatHistoryNavigateToNewChat?.($event)"
                           class="group flex items-center gap-2"><div class="ista-brand-title text-xl text-ista-primary not-italic transition-transform duration-300 group-hover:scale-105">ISTA <span class="font-light italic text-ista-gold">AI</span></div></a>
                    </div>

                    <!-- Center: Tab Toggle -->
                    <div class="flex items-center">
                        @include('livewire.chat.partials.chat-memo-tab-toggle')
                    </div>

                    <!-- Right toggles -->
                    <div class="flex items-center gap-1 sm:gap-3">
                        <!-- Theme Toggle Button -->
                        <button type="button" @click="darkMode = !darkMode" :aria-pressed="darkMode ? 'true' : 'false'" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors" aria-label="Ubah tema gelap">
                            <svg x-show="darkMode === false" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#64748B]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                            </svg>
                            <svg x-show="darkMode === true" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                            </svg>
                        </button>

                        <button type="button" @click="showRightSidebar = !showRightSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0" :aria-label="showRightSidebar ? 'Sembunyikan sidebar dokumen' : 'Tampilkan sidebar dokumen'">
                            <img src="{{ $uiIcons['collapseRightLight'] }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showRightSidebar ? 'rotate-0' : 'rotate-180'" />
                            <img src="{{ $uiIcons['collapseRightDark'] }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showRightSidebar ? 'rotate-0' : 'rotate-180'" />
                        </button>
                    </div>
                </div>

                <!-- Messages List -->
                @include('livewire.chat.partials.chat-messages')

                <!-- Input Area (Composer) -->
                @include('livewire.chat.partials.chat-composer', ['prompt' => $prompt])

        </main>

        <!-- RIGHT SIDEBAR: Documents -->
        @include('livewire.chat.partials.chat-right-sidebar')
    </div>

    {{-- ===== MEMO TAB CONTENT ===== --}}
    <div x-show="activeTab === 'memo'" x-cloak class="flex w-full h-full overflow-hidden">
        <livewire:memos.memo-workspace />
    </div>

    {{-- ===== PROMPY TAB CONTENT (kept on presentation tab key for URL compatibility) ===== --}}
    @if($presentationEnabled)
    <div x-show="activeTab === 'presentation'" x-cloak class="flex w-full h-full overflow-hidden">
        <livewire:presentations.presentation-workspace />
    </div>
    @endif

    <!-- Drag & Drop Overlay Visual -->
    <div x-show="isDraggingFile" x-cloak x-transition.opacity class="fixed inset-0 z-[60] bg-ista-primary/10 backdrop-blur-[2px] flex items-center justify-center pointer-events-none">
        <div class="h-[120px] w-[320px] rounded-2xl border-2 border-dashed border-ista-primary bg-white/90 dark:bg-gray-900/90 shadow-2xl flex flex-col items-center justify-center gap-3 scale-110 transition-transform">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-ista-primary animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <span class="text-[15px] font-bold text-ista-primary">Drop file di mana saja untuk upload</span>
        </div>
    </div>

    <!-- Unified Mobile Backdrop -->
    <div
        x-show="isMobile && (showLeftSidebar || showRightSidebar)"
        x-transition.opacity
        @click="showLeftSidebar = false; showRightSidebar = false;"
        class="fixed inset-0 bg-black/50 z-40"
        style="display:none;"
    ></div>

</div>
