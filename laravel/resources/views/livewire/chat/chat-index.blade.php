<div x-data="{ 
        darkMode: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
        isMobile: window.matchMedia('(max-width: 1023px)').matches,
        showLeftSidebar: !window.matchMedia('(max-width: 1023px)').matches,
        showRightSidebar: !window.matchMedia('(max-width: 1023px)').matches,
        isDraggingFile: false,
        dragDepth: 0,
        dropError: '',
        sendError: '',
        messageAcked: false,
        promptDraft: @js($prompt),
        isSendingMessage: false,
        optimisticUserMessage: '',
        scrollToBottom(smooth = false) {
            const chatBox = this.$refs.chatBox;
            if (!chatBox) return;

            chatBox.scrollTo({
                top: chatBox.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        },
        submitPrompt(event) {
            if (event) event.preventDefault();
            if (this.isSendingMessage) return;

            const text = this.promptDraft.trim();
            if (!text) return;

            this.sendError = '';
            this.messageAcked = false;
            this.isSendingMessage = true;
            this.optimisticUserMessage = text;
            this.promptDraft = '';
            this.$dispatch('message-send');
            this.$nextTick(() => this.scrollToBottom());

            this.$wire.sendMessage(text)
                .then(() => {
                    this.$nextTick(() => this.scrollToBottom());
                })
                .catch((error) => {
                    this.optimisticUserMessage = '';

                    if (!this.messageAcked) {
                        this.promptDraft = text;
                        this.sendError = 'Pesan gagal dikirim. Periksa koneksi lalu coba lagi.';
                    } else {
                        this.sendError = 'Pesan sudah terkirim, tetapi jawaban ISTA AI gagal diproses. Coba kirim ulang prompt Anda.';
                    }

                    setTimeout(() => {
                        if (this.sendError) this.sendError = '';
                    }, 6000);

                    console.error('Send message error:', error);
                })
                .finally(() => {
                    this.isSendingMessage = false;
                    this.$dispatch('message-complete');
                    this.$nextTick(() => this.scrollToBottom());
                });
        },
        initChatBehavior() {
            this.$nextTick(() => this.scrollToBottom());

            const chatBox = this.$refs.chatBox;
            if (chatBox) {
                const observer = new MutationObserver(() => this.scrollToBottom());
                observer.observe(chatBox, { childList: true, subtree: true, characterData: true });
                window.addEventListener('beforeunload', () => observer.disconnect(), { once: true });
            }

            this.$wire.on('assistant-output', () => {
                this.$nextTick(() => this.scrollToBottom());
            });

            this.$wire.on('user-message-acked', () => {
                this.messageAcked = true;
                this.optimisticUserMessage = '';
                this.$nextTick(() => this.scrollToBottom());
            });

            // Use matchMedia for reliable responsive detection
            const mql = window.matchMedia('(max-width: 1023px)');
            const handleMqlChange = (e) => {
                const wasMobile = this.isMobile;
                this.isMobile = e.matches;
                if (wasMobile && !this.isMobile) {
                    this.showLeftSidebar = true;
                    this.showRightSidebar = true;
                } else if (!wasMobile && this.isMobile) {
                    this.showLeftSidebar = false;
                    this.showRightSidebar = false;
                }
            };
            mql.addEventListener('change', handleMqlChange);
            window.addEventListener('beforeunload', () => mql.removeEventListener('change', handleMqlChange), { once: true });
        },

        setDropError(message) {
            this.dropError = message;
            setTimeout(() => {
                if (this.dropError === message) {
                    this.dropError = '';
                }
            }, 3500);
        },
        onDragEnter(event) {
            if (!event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
            this.dragDepth += 1;
            this.isDraggingFile = true;
        },
        onDragOver(event) {
            if (!event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
            this.isDraggingFile = true;
        },
        onDragLeave(event) {
            if (!event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
            this.dragDepth = Math.max(this.dragDepth - 1, 0);
            if (this.dragDepth === 0) this.isDraggingFile = false;
        },
        onDropFile(event) {
            try {
                const files = event.dataTransfer ? event.dataTransfer.files : null;
                this.dragDepth = 0;
                this.isDraggingFile = false;

                if (!files || files.length === 0 || !$refs.chatAttachmentInput) return;
                if (files.length > 1) {
                    this.setDropError('Hanya bisa upload 1 file sekaligus.');
                    return;
                }

                const file = files[0];
                const maxSize = 50 * 1024 * 1024;
                if (file.size > maxSize) {
                    this.setDropError('File terlalu besar. Maksimal 50MB.');
                    return;
                }

                const allowedMimeTypes = [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ];
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const allowedExtensions = ['pdf', 'docx', 'xlsx'];

                if (!allowedMimeTypes.includes(file.type) || !allowedExtensions.includes(extension)) {
                    this.setDropError('Format file tidak didukung. Gunakan PDF, DOCX, atau XLSX.');
                    return;
                }

                this.dropError = '';
                $refs.chatAttachmentInput.files = files;
                $refs.chatAttachmentInput.dispatchEvent(new Event('change', { bubbles: true }));
                this.showRightSidebar = true;
            } catch (error) {
                console.error('Upload error:', error);
                this.setDropError('Gagal upload file. Silakan coba lagi.');
            }
        },
        openAttachmentPicker() {
            this.showRightSidebar = true;
            this.dropError = '';
            if (!$refs.chatAttachmentInput) return;
            $refs.chatAttachmentInput.value = '';
            $refs.chatAttachmentInput.click();
        },
        autoResizeTextarea(el) {
            el.style.height = 'auto';
            const minHeight = 44;
            const maxHeight = 200;
            el.style.height = Math.min(Math.max(el.scrollHeight, minHeight), maxHeight) + 'px';
            el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden';
        }
     }"
     x-on:dragenter.window.prevent="onDragEnter($event)"
     x-on:dragover.window.prevent="onDragOver($event)"
     x-on:dragleave.window.prevent="onDragLeave($event)"
     x-on:drop.window.prevent="onDropFile($event)"
     x-init="initChatBehavior(); $watch('darkMode', val => { localStorage.setItem('theme', val ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', val); }); document.documentElement.classList.toggle('dark', darkMode); if(promptDraft) { setTimeout(() => submitPrompt(), 100); }"
     class="flex h-screen w-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900" style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
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


    <!-- LEFT SIDEBAR: Chat History -->
    <aside 
        :class="[
            showLeftSidebar ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-full pointer-events-none',
            isMobile ? 'fixed left-0 top-0 h-full w-[288px] shadow-2xl border-r border-stone-200/60 dark:border-[#1E293B]' : (showLeftSidebar ? 'relative w-[288px] border-r border-stone-200/60 dark:border-[#1E293B]' : 'relative w-0 border-r border-transparent')
        ]"
        @click.stop
        class="z-50 flex-shrink-0 overflow-hidden bg-white dark:bg-gray-900 flex flex-col transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]">
        
        <!-- Left Sidebar Header with Close on Mobile -->
        <div class="flex items-center justify-between px-4 pb-2 pt-3">
            <a href="{{ route('dashboard') }}" @click="showLeftSidebar = false" class="inline-flex items-center px-1 py-2.5 font-medium text-[13px] text-gray-700 dark:text-gray-200 hover:text-amber-800 dark:hover:text-amber-300 transition-colors duration-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Kembali ke Beranda
            </a>
            <!-- Close button, only visible on mobile -->
            <button type="button" x-show="isMobile" @click="showLeftSidebar = false" class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors" aria-label="Tutup sidebar">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- New Chat Button -->
        <div class="p-4 pt-2 pb-5">
            <button type="button" @click="$wire.startNewChat().then(() => { if(isMobile) showLeftSidebar = false; })" class="w-full flex items-center justify-start px-4 py-2.5 rounded-lg border border-stone-200/60 dark:border-[#334155] dark:bg-transparent bg-white hover:bg-gray-50 dark:hover:bg-white/5 font-medium text-[13px] text-gray-700 dark:text-gray-200 transition-all duration-200 shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-[#64748B] dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14m-7-7h14" />
                </svg>
                New Chat
            </button>
        </div>

        <div class="flex-1 overflow-y-auto overflow-x-hidden px-4">
            <!-- TODAY -->
            <div class="mb-6">
                <h3 class="text-[11.6px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2">Today</h3>
                <ul class="space-y-1">
                    @php
                        $visibleChats = $conversations->take(10);
                        $olderChats = $conversations->skip(10);
                    @endphp
                    
                    @foreach($visibleChats as $conversation)
                        <li class="group relative">
                            <button type="button" @click="$wire.loadConversation({{ $conversation->id }}).then(() => { if(isMobile) showLeftSidebar = false; })"
                               class="w-full text-left px-3 py-2 rounded-md flex items-center transition-colors duration-200 {{ $currentConversationId == $conversation->id ? 'bg-white/80 shadow-sm border border-stone-200 text-stone-800 dark:bg-[#1E293B] dark:border-[#334155] dark:text-white font-medium' : 'hover:bg-black/5 dark:hover:bg-white/5 text-stone-700 dark:text-gray-300' }}">
                               <!-- Chat icon -->
                                         <img src="{{ $uiIcons['historyLight'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 dark:hidden" />
                                         <img src="{{ $uiIcons['historyDark'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 hidden dark:block" />
                               <span class="truncate text-[13.2px]" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                            </button>
                            <button type="button" wire:click="deleteConversation({{ $conversation->id }})"
                                    wire:confirm="Delete this chat?"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-md opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-500/20 text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-all duration-200"
                                    title="Delete chat">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
            
            @if($olderChats->count() > 0)
            <div class="mb-6">
                <button type="button" wire:click="toggleOlderChats" class="flex items-center justify-between w-full text-left">
                    <h3 class="text-[11.3px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2">Previous 7 Days</h3>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-[#64748B] dark:text-[#94A3B8] transition-transform duration-200 {{ $showOlderChats ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                @if($showOlderChats)
                    <ul class="mt-1 space-y-1">
                        @foreach($olderChats as $conversation)
                            <li class="group relative">
                                <button type="button" @click="$wire.loadConversation({{ $conversation->id }}).then(() => { if(isMobile) showLeftSidebar = false; })"
                                   class="w-full text-left px-3 py-2 rounded-md flex items-center transition-colors duration-200 {{ $currentConversationId == $conversation->id ? 'bg-white/80 shadow-sm border border-stone-200 text-stone-800 dark:bg-[#1E293B] dark:border-[#334155] dark:text-white font-medium' : 'hover:bg-black/5 dark:hover:bg-white/5 text-stone-700 dark:text-gray-300' }}">
                                    <!-- Chat icon -->
                                    <img src="{{ $uiIcons['historyLight'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 dark:hidden" />
                                    <img src="{{ $uiIcons['historyDark'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 hidden dark:block" />
                                    <span class="truncate text-[13.2px]" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                                </button>
                                <button type="button" wire:click="deleteConversation({{ $conversation->id }})"
                                        wire:confirm="Delete this chat?"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-md opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-500/20 text-gray-400 hover:text-red-600 transition-all duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @endif
        </div>

        <!-- Upgrade / Profile area at bottom -->
        <div class="px-4 py-6 text-sm flex flex-col gap-2">
             <a href="/profile" class="flex items-center gap-3 text-gray-700 dark:text-[#F8FAFC] hover:opacity-80 transition-opacity">
                <!-- Upgrade Icon -->
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

    <!-- CENTER MAIN: Chat Area -->
    <main class="flex-1 flex flex-col relative w-full h-full bg-transparent z-0 overflow-hidden min-w-0">
        
        <!-- Header for Chat Space -->
        <div class="h-[61px] flex-shrink-0 flex items-center justify-between px-3 sm:px-6 z-20 border-b border-stone-200/60/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
            <!-- Left toggler and title -->
            <div class="flex items-center gap-2 sm:gap-4">
                <button type="button" @click="showLeftSidebar = !showLeftSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0">
                    <img src="{{ $uiIcons['collapseLeftLight'] }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                    <img src="{{ $uiIcons['collapseLeftDark'] }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                </button>
                <button type="button" wire:click="startNewChat" class="group flex items-center gap-2"><div class="ista-brand-title text-xl text-ista-primary not-italic transition-transform duration-300 group-hover:scale-105">ISTA <span class="font-light italic text-ista-gold">AI</span></div></button>
            </div>

            <!-- Right toggles -->
            <div class="flex items-center gap-1 sm:gap-3">
                <!-- Theme Toggle Button -->
                <button type="button" @click="darkMode = !darkMode" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors">
                    <svg x-show="darkMode === false" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#64748B]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                    </svg>
                    <svg x-show="darkMode === true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                    </svg>
                </button>

                <button type="button" @click="showRightSidebar = !showRightSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors flex-shrink-0">
                    <img src="{{ $uiIcons['collapseRightLight'] }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showRightSidebar ? 'rotate-0' : 'rotate-180'" />
                    <img src="{{ $uiIcons['collapseRightDark'] }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showRightSidebar ? 'rotate-0' : 'rotate-180'" />
                </button>
            </div>
        </div>

        <div x-show="sendError" x-transition class="px-6 pt-3 pb-1">
            <div class="mx-auto max-w-3xl rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-[13px] text-rose-800 dark:border-rose-800/40 dark:bg-rose-950/30 dark:text-rose-200">
                <div class="flex items-start justify-between gap-3">
                    <p class="leading-relaxed" x-text="sendError"></p>
                    <button type="button" class="shrink-0 text-rose-500 hover:text-rose-700 dark:text-rose-300 dark:hover:text-rose-100" x-on:click="sendError = ''" aria-label="Tutup notifikasi">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Messages List -->
        <div class="flex-1 overflow-y-auto px-3 sm:px-6 py-6 sm:py-8 space-y-6 sm:space-y-8" x-ref="chatBox" x-on:message-streamed.window="$refs.chatBox.scrollTop = $refs.chatBox.scrollHeight">
            @if(empty($messages))
                <div class="h-full flex flex-col items-center justify-center text-center">
                    <div class="h-16 w-16 mb-6">
                        <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
                    </div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">ISTA AI Assistant</h2>
                    <p class="text-gray-500 dark:text-[#94A3B8] text-[14px]">
                        Start a new conversation to get started. 
                    </p>
                </div>
            @endif

            @foreach($messages as $message)
                @php
                    $isUserMessage = $message['role'] == 'user';
                @endphp
                <div class="flex {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                    <div class="w-full sm:max-w-3xl flex items-start gap-2 sm:gap-4 px-0 sm:px-8 {{ $isUserMessage ? 'flex-row-reverse' : '' }}">
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center {{ $message['role'] == 'user' ? 'bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black' : 'bg-white border border-stone-200 shadow-sm p-1' }}">
                            @if($message['role'] == 'user')
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            @else
                                <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
                            @endif
                        </div>

                        <div class="flex flex-col gap-1 w-full {{ $isUserMessage ? 'items-end text-right' : 'items-start text-left' }}">
                            @php
                                $messageTime = !empty($message['created_at'])
                                    ? \Illuminate\Support\Carbon::parse($message['created_at'])->timezone('Asia/Jakarta')->format('H:i') . ' WIB'
                                    : null;
                            @endphp
                            <div class="flex items-center gap-2 mb-1 {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                                <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">{{ $message['role'] == 'user' ? 'You' : 'ISTA AI' }}</span>
                                @if($messageTime)
                                    <span class="text-[10px] text-gray-400 dark:text-[#64748B]">{{ $messageTime }}</span>
                                @endif
                            </div>

                            @if($message['role'] == 'assistant')
                                @php
                                    $assistantHtml = str($message['content'])->markdown([
                                        'html_input' => 'strip',
                                        'allow_unsafe_links' => false,
                                    ]);
                                @endphp
                                @if($message['id'] == $newMessageId)
                                    <div 
                                        wire:ignore
                                        wire:key="msg-typing-{{ $message['id'] }}"
                                        class="rounded-xl bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 px-4 py-3 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 max-w-[656px] prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-stone-800 prose-a:text-sky-700 prose-a:decoration-sky-600/80 hover:prose-a:text-sky-800 dark:prose-headings:text-white dark:prose-p:text-gray-100 dark:prose-strong:text-white dark:prose-ul:text-gray-100 dark:prose-ol:text-gray-100 dark:prose-li:text-gray-100 dark:prose-li:marker:text-white dark:prose-a:text-sky-300 dark:prose-a:decoration-sky-300/90 dark:hover:prose-a:text-sky-200 pb-1"
                                        x-data="{ 
                                            content: @js((string) $assistantHtml), 
                                            displayedContent: '', 
                                            typewriterEffect() {
                                                let i = 0;
                                                const type = () => {
                                                    if (i < this.content.length) {
                                                        const remaining = this.content.length - i;
                                                        const chunkSize = remaining > 1400 ? 12 : (remaining > 800 ? 9 : 6);
                                                        const speed = remaining > 1400 ? 4 : (remaining > 800 ? 6 : 10);

                                                        let nextChunk = this.content.substring(i, i + chunkSize);
                                                        if (nextChunk.startsWith('<')) {
                                                            const tagEnd = this.content.indexOf('>', i);
                                                            if (tagEnd !== -1) {
                                                                nextChunk = this.content.substring(i, tagEnd + 1);
                                                            }
                                                        } else {
                                                            const nextTagStart = nextChunk.indexOf('<');
                                                            if (nextTagStart > 0) {
                                                                nextChunk = this.content.substring(i, i + nextTagStart);
                                                            }
                                                        }
                                                        
                                                        this.displayedContent += nextChunk;
                                                        i += nextChunk.length;
                                                        
                                                        setTimeout(type, speed);
                                                    }
                                                };
                                                setTimeout(type, 80);
                                            }
                                        }" 
                                        x-init="typewriterEffect()"
                                        x-html="displayedContent"
                                    >
                                    </div>
                                @else
                                    <div 
                                        wire:key="msg-static-{{ $message['id'] }}"
                                        class="rounded-xl bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 px-4 py-3 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 max-w-[656px] prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-stone-800 prose-a:text-sky-700 prose-a:decoration-sky-600/80 hover:prose-a:text-sky-800 dark:prose-headings:text-white dark:prose-p:text-gray-100 dark:prose-strong:text-white dark:prose-ul:text-gray-100 dark:prose-ol:text-gray-100 dark:prose-li:text-gray-100 dark:prose-li:marker:text-white dark:prose-a:text-sky-300 dark:prose-a:decoration-sky-300/90 dark:hover:prose-a:text-sky-200 pb-1"
                                        x-html="@js((string) $assistantHtml)"
                                    >
                                    </div>
                                @endif
                            @else
                                <div class="text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 max-w-[656px]">
                                    <p class="whitespace-pre-wrap">{{ $message['content'] }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            <template x-if="optimisticUserMessage">
                <div class="flex justify-end">
                    <div class="w-full sm:max-w-3xl flex items-start gap-4 px-2 sm:px-8 flex-row-reverse">
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                        <div class="flex flex-col gap-1 w-full items-end text-right">
                            <div class="flex items-center gap-2 mb-1 justify-end">
                                <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">You</span>
                            </div>
                            <div class="text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 max-w-[656px]">
                                <p class="whitespace-pre-wrap" x-text="optimisticUserMessage"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- AI Streaming Output -->
            <div x-data="{ streaming: false, text: '', modelName: '', sources: [] }" 
                 x-on:message-send.window="streaming = true; text = ''; modelName = ''; sources = []"
                  x-on:message-complete.window="streaming = false; text = ''; modelName = ''; sources = []"
                 x-init="
                    $wire.on('assistant-output', (data) => { text += data[0]; streaming = true; });
                    $wire.on('model-name', (data) => { modelName = data[0]; });
                    $wire.on('assistant-sources', (data) => { sources = data[0]; });
                          $wire.on('assistant-message-persisted', () => { streaming = false; text = ''; modelName = ''; sources = []; });
                  "
                 class="flex justify-start"
                 x-show="streaming">
                  <div class="w-full sm:max-w-3xl flex flex-row items-start gap-4 px-2 sm:px-8">
                     <div class="shrink-0 h-8 w-8 rounded-full bg-white border border-stone-200 shadow-sm p-1 flex items-center justify-center">
                         <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
                     </div>
                     <div class="flex flex-col gap-1 items-start w-full">
                         <div class="flex items-center gap-2 mb-1">
                             <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">ISTA AI</span>
                             <span x-show="modelName" class="text-[10px] bg-white/80 shadow-sm border border-stone-200 px-1.5 py-0.5 rounded text-gray-600 dark:text-gray-300" x-text="modelName"></span>
                         </div>
                         <div class="rounded-xl bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100"
                              :class="text === '' ? 'inline-flex items-center px-4 py-3 w-auto' : 'px-4 py-3 w-full max-w-[656px]'">
                             <div x-show="text === ''" class="flex space-x-1.5 py-1">
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce"></div>
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce [animation-delay:-0.15s]"></div>
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce [animation-delay:-0.3s]"></div>
                             </div>
                             <p x-show="text !== ''" class="whitespace-pre-wrap" x-text="text"></p>
                         </div>

                          {{-- Sources: muncul di bawah bubble saat streaming (menampilkan real-time data context/web) --}}
                          <template x-if="sources && Array.isArray(sources) && sources.length > 0">
                              <div class="mt-2.5 w-full text-left"
                                   x-transition:enter="transition ease-out duration-300 transform"
                                   x-transition:enter-start="opacity-0 translate-y-2"
                                   x-transition:enter-end="opacity-100 translate-y-0">
                                  <p class="text-[10px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2 flex items-center gap-1.5 pl-0.5">
                                      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                      </svg>
                                      Sumber Referensi:
                                  </p>
                                  <div class="flex flex-wrap gap-2">
                                      <template x-for="(source, idx) in sources" :key="idx">
                                          <div>
                                              {{-- WEB SOURCE: Clickable Link --}}
                                              <template x-if="source.type === 'web' && source.url">
                                                  <a :href="source.url" target="_blank" rel="noopener noreferrer"
                                                     :title="source.snippet || source.title"
                                                     class="group inline-flex items-start gap-2 px-3 py-2 rounded-lg text-[11px] font-medium bg-white dark:bg-[#1E293B] border border-sky-100 dark:border-sky-900/50 shadow-sm text-sky-700 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-900/30 hover:border-sky-300 dark:hover:border-sky-700 transition-all duration-200 hover:-translate-y-0.5 max-w-[300px]">
                                                      <svg class="w-3.5 h-3.5 shrink-0 text-sky-500 transition-transform group-hover:scale-110 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                                                      </svg>
                                                      <div class="flex flex-col min-w-0 pr-1">
                                                          <span class="truncate block w-full font-bold leading-tight mb-0.5" x-text="source.title || (new URL(source.url)).hostname"></span>
                                                          <span class="truncate block w-full text-[9.5px] opacity-80 font-mono tracking-tight" x-text="source.url"></span>
                                                      </div>
                                                      <svg class="w-3 h-3 shrink-0 opacity-40 group-hover:opacity-100 transition-opacity mt-0.5 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                      </svg>
                                                  </a>
                                              </template>

                                              {{-- DOCUMENT SOURCE: Non-clickable Chip --}}
                                              <template x-if="source.type !== 'web' || !source.url">
                                                  <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-medium bg-stone-50 dark:bg-gray-800/80 border border-stone-200 dark:border-gray-700 text-stone-600 dark:text-gray-300 max-w-[260px] shadow-sm">
                                                      <svg class="w-3.5 h-3.5 shrink-0 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                      </svg>
                                                      <span class="truncate block max-w-[200px]" x-text="source.filename || 'Dokumen Office'"></span>
                                                  </div>
                                              </template>
                                          </div>
                                      </template>
                                  </div>
                              </div>
                          </template>

                     </div>
                  </div>
            </div>
        </div>

        <!-- Input Area container -->
        <div class="px-3 sm:px-6 pb-4 sm:pb-6 pt-2 bg-transparent w-full">
            @php
                $chatDocuments = $availableDocuments->whereIn('id', $conversationDocuments)->values();
            @endphp
            <input
                x-ref="chatAttachmentInput"
                type="file"
                wire:model.live="chatAttachment"
                accept=".pdf,.docx,.xlsx"
                class="hidden"
            >
            <form x-on:submit.prevent="submitPrompt($event)" class="chat-form max-w-3xl mx-auto relative rounded-xl shadow-sm bg-white dark:bg-gray-800 border border-stone-200/60 dark:border-gray-700 transition-colors">
                <div class="flex flex-col w-full">
                    <div wire:loading.flex wire:target="chatAttachment" class="px-5 pt-4 items-center gap-2 text-[12px] text-ista-primary dark:text-[#8E81FF]">
                        <span class="h-2 w-2 rounded-full bg-current animate-ping"></span>
                    </div>

                    @if($isUploadingAttachment)
                        <div class="px-5 pt-4 flex items-center gap-2 text-[12px] text-ista-primary dark:text-[#8E81FF]">
                            <span class="h-2 w-2 rounded-full bg-current animate-pulse"></span>
                        </div>
                    @endif
                    @if($chatDocuments->count() > 0)
                        <div class="px-5 pt-5 pb-1 flex flex-wrap gap-3">
                            @foreach($chatDocuments as $doc)
                                @php
                                    $fileExt = $doc->extension ?? strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION));
                                @endphp
                                <span class="inline-flex items-center gap-2 bg-[#E2E8F0] dark:bg-gray-700 dark:border dark:border-gray-600 text-[#314158] dark:text-gray-100 rounded-2xl px-4 py-2 text-[14px]">
                                    @if($fileExt === 'pdf')
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#FF2056]" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path></svg>
                                    @elseif($fileExt === 'xlsx')
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="#32CD32"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                    @elseif($fileExt === 'docx')
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#2B7FFF]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h6" /></svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4" />
                                        </svg>
                                    @endif
                                    <span class="max-w-[180px] truncate">{{ $doc->original_name }}</span>
                                    <button type="button" wire:click="removeConversationDocument({{ $doc->id }})" class="text-[#7C8DA8] hover:text-[#314158] dark:text-gray-300 dark:hover:text-white" title="Remove">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if($isUploadingAttachment && $uploadingAttachmentName)
                        <div class="px-5 pt-3 pb-1 flex flex-wrap gap-3">
                            <span class="inline-flex items-center gap-2 bg-ista-primary/5 dark:bg-[#312E81]/30 text-ista-primary dark:text-[#C7D2FE] rounded-2xl px-4 py-2 text-[13px]">
                                <span class="w-4 h-4 rounded-full border-2 border-[#C7D2FE] border-t-[#4A4AF4] animate-spin"></span>
                                <span class="max-w-[190px] truncate">{{ $uploadingAttachmentName }}</span>
                            </span>
                        </div>
                    @endif
                    <div x-show="!isDraggingFile" class="px-3 pb-3 pt-3 w-full">
                        <textarea 
                            x-ref="chatInput"
                            x-model="promptDraft"
                            x-on:keydown.enter.prevent="if($event.shiftKey) return; submitPrompt($event)"
                            x-on:input="autoResizeTextarea($el)"
                            placeholder="Message ISTA AI..." 
                            class="chat-input w-full max-h-[200px] min-h-[44px] bg-transparent border-none focus:ring-0 focus:outline-none focus:border-transparent focus-visible:ring-0 focus-visible:outline-none resize-none text-[14.5px] text-stone-800 dark:text-[#F8FAFC] placeholder-[#94A3B8] dark:placeholder-[#64748B] px-2 py-[10px] hover:bg-transparent focus:bg-transparent"
                            rows="1"
                            style="outline: none !important; box-shadow: none !important;"
                        ></textarea>
                        
                        <div class="flex items-center justify-between mt-2">
                            <!-- Toggle Search (kiri) -->
                            <button type="button" wire:click="toggleWebSearch" class="h-[30px] px-[13px] rounded-full text-[11.4px] font-normal flex items-center gap-[6px] transition-all duration-300 {{ $webSearchMode ? 'bg-blue-50 dark:bg-blue-500/10 border border-blue-400 dark:border-blue-500 text-blue-600 dark:text-blue-400' : 'bg-transparent border border-stone-200 dark:border-[#334155] text-[#62748E] dark:text-[#94A3B8]' }}">
                                <svg class="w-[14px] h-[14px] text-current" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M7 12.8333C10.2217 12.8333 12.8333 10.2217 12.8333 7C12.8333 3.77834 10.2217 1.16667 7 1.16667C3.77834 1.16667 1.16667 3.77834 1.16667 7C1.16667 10.2217 3.77834 12.8333 7 12.8333Z" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M7 1.16667C5.50214 2.73942 4.66667 4.8281 4.66667 7C4.66667 9.1719 5.50214 11.2606 7 12.8333C8.49786 11.2606 9.33333 9.1719 9.33333 7C9.33333 4.8281 8.49786 2.73942 7 1.16667Z" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M1.16667 7H12.8333" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>Search</span>
                            </button>

                            <!-- Action buttons (kanan) -->
                            <div class="flex items-center gap-2">
                                <button type="button" @click="openAttachmentPicker()" wire:loading.attr="disabled" wire:target="chatAttachment" class="h-[34px] w-[34px] rounded-full transition-colors flex items-center justify-center bg-transparent hover:bg-[#F1F5F9] dark:hover:bg-gray-800 disabled:opacity-60" title="Attach file">
                                    <img src="{{ $uiIcons['uploadLight'] }}" alt="" class="h-[18px] w-[18px] dark:hidden" />
                                    <img src="{{ $uiIcons['uploadDark'] }}" alt="" class="h-[18px] w-[18px] hidden dark:block" />
                                </button>

                                <!-- Send -->
                                <button type="submit" 
                                        :disabled="isSendingMessage"
                                        class="bg-ista-primary hover:bg-ista-dark dark:bg-ista-primary dark:hover:bg-ista-dark disabled:opacity-50 rounded-full transition-all duration-300 h-[32px] w-[32px] flex items-center justify-center group">
                                    <img src="{{ $uiIcons['sendLight'] }}" alt="" class="h-[17px] w-[17px] dark:hidden brightness-0 invert" />
                                    <img src="{{ $uiIcons['sendDark'] }}" alt="" class="h-[17px] w-[17px] hidden dark:block brightness-0 invert" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <div x-show="isDraggingFile" x-transition.opacity class="px-3 pb-3 pt-3 w-full">
                        <div class="h-[84px] w-full rounded-xl border-2 border-dashed border-ista-primary/40 dark:border-[#8E81FF] bg-ista-primary/5 dark:bg-[#312E81]/20 flex items-center justify-center gap-2 text-[13px] font-semibold text-ista-primary dark:text-[#A5B4FC]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            Drop file di sini untuk upload
                        </div>
                    </div>
                </div>
            </form>
            <p x-show="dropError" x-transition.opacity class="max-w-3xl mx-auto mt-2 text-xs text-red-500 dark:text-red-400" x-text="dropError"></p>
            <div class="text-center mt-3 text-[11px] text-[#94A3B8] dark:text-[#64748B]">
                ISTA AI can make mistakes. Consider verifying critical information.
            </div>
        </div>
    </main>

    <!-- RIGHT SIDEBAR: Documents -->
    <aside 
        :class="[
            showRightSidebar ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-full pointer-events-none',
            isMobile ? 'fixed right-0 top-0 h-full w-[288px] shadow-2xl border-l border-stone-200/60 dark:border-[#1E293B]' : (showRightSidebar ? 'relative w-[288px] border-l border-stone-200/60 dark:border-[#1E293B]' : 'relative w-0 border-l border-transparent')
        ]"
        @click.stop
        class="z-50 flex-shrink-0 overflow-hidden bg-white dark:bg-gray-900 flex flex-col transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]">
        
        <div class="px-4 pt-5 pb-0 flex items-center justify-between">
            <span class="inline-flex items-center font-medium text-[13px] text-gray-700 dark:text-gray-200">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                Semua Dokumen Saya
            </span>
            <!-- Close button, only visible on mobile -->
            <button type="button" x-show="isMobile" @click="showRightSidebar = false" class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors" aria-label="Tutup sidebar dokumen">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
<div class="flex-1 overflow-y-auto px-4 pt-4" @if($hasDocumentsInProgress) wire:poll.3s="loadAvailableDocuments" @else wire:poll.20s="loadAvailableDocuments" @endif>
              <div class="mb-4">
                  @php
                     $readyDocumentIds = $availableDocuments->where('status', 'ready')->pluck('id')->map(fn ($id) => (int) $id)->toArray();
                     $selectedIds = array_map('intval', $selectedDocuments);
                     $selectedInAvailableCount = count(array_intersect($selectedIds, $readyDocumentIds));
                     $allDocumentsSelected = count($readyDocumentIds) > 0 && $selectedInAvailableCount === count($readyDocumentIds);
                 @endphp
                 <div class="flex items-center flex-nowrap gap-0.5 mb-4 px-1 pb-3 border-b border-stone-200/60/70 dark:border-gray-800/70">
                     <button type="button" wire:click="toggleSelectAllDocuments" aria-pressed="{{ $allDocumentsSelected ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 text-[#62748E] dark:text-[#90A1B9] hover:text-[#314158] dark:hover:text-white text-[11px] leading-[1.1] font-semibold px-1.5 py-1 rounded-md hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors whitespace-nowrap">
                         @if($allDocumentsSelected)
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-ista-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <rect x="3" y="3" width="18" height="18" rx="4" stroke-width="2"></rect>
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12.5l2.8 2.8L16.8 9.3" />
                             </svg>
                             Deselect All
                         @else
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#64748B] dark:text-[#94A3B8]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <rect x="3" y="3" width="18" height="18" rx="4" stroke-width="2"></rect>
                             </svg>
                             Select All
                         @endif
                     </button>
                     @if($selectedInAvailableCount > 0)
                         <button type="button" wire:click="deleteSelectedDocuments" wire:confirm="Delete selected files from your documents?" class="inline-flex shrink-0 items-center gap-1 text-[#FF2056] text-[10.5px] font-semibold px-1.5 py-1 rounded-md bg-[#FF2056]/10 hover:bg-[#FF2056]/20 transition-colors whitespace-nowrap">
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                             </svg>
                             Delete
                         </button>
                         <button type="button" @click="$wire.addSelectedDocumentsToChat().then(() => { if (isMobile) showRightSidebar = false; })" class="ml-2 inline-flex shrink-0 items-center gap-1 text-white text-[10.5px] font-semibold px-1.5 py-1 rounded-md bg-ista-primary hover:bg-stone-800 transition-all whitespace-nowrap">
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                             </svg>
                             Add to Chat
                         </button>
                     @endif
                 </div>
                 
                 @if(count($availableDocuments) > 0)
                     <div class="space-y-3">
                         @foreach($availableDocuments as $doc)
                             @php
                                 $isSelected = in_array($doc->id, $selectedDocuments);
                                 $isReady = $doc->status === 'ready';
                                 $isLoading = in_array($doc->status, ['pending', 'processing']);
                                 $ext = $doc->extension ?? strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION));
                                 $size = $doc->formatted_size ?? 'Ukuran tidak tersedia';
                             @endphp
                             <label class="flex items-center gap-3 h-[62px] px-3 rounded-lg border cursor-pointer transition-all duration-200
                                 {{ $isSelected ? 'bg-white/95 dark:bg-gray-800 border-ista-primary/40 dark:border-ista-primary/40 shadow-[0_1px_4px_rgba(97,95,255,0.25)]' : 'bg-white dark:bg-gray-800 border-stone-200/60 dark:border-gray-700 hover:border-[#CBD5E1] dark:hover:border-gray-600' }} {{ $isLoading ? 'animate-pulse' : '' }}">
                                 @if($isLoading)
                                     <div class="w-3.5 h-3.5 rounded-full border-2 border-[#CBD5E1] dark:border-[#334155] border-t-[#615FFF] dark:border-t-[#8E81FF] animate-spin"></div>
                                 @else
                                     <input type="checkbox" wire:model.live="selectedDocuments" value="{{ $doc->id }}"
                                         class="rounded text-ista-primary focus:ring-ista-primary bg-white dark:bg-transparent border-[#CBD5E1] dark:border-[#64748B] w-3.5 h-3.5 cursor-pointer aspect-square"
                                         {{ $isReady ? '' : 'disabled' }}>
                                 @endif
                                 <div class="h-[34px] w-[34px] rounded-lg bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-[#334155] flex items-center justify-center">
                                     @if($ext === 'pdf')
                                         <svg class="w-[18px] h-[18px] text-[#FF2056] shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path></svg>
                                     @elseif($ext === 'txt')
                                         <svg class="w-[18px] h-[18px] text-[#62748E] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h6" /></svg>
                                      @elseif($ext === 'xlsx')
                                          <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="#32CD32"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                      @elseif(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'img']))
                                          <svg class="w-[18px] h-[18px] text-[#FD9A00] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm4 4h.01M21 15l-5-5-7 7-3-3-3 3" /></svg>
                                      @else
                                          <svg class="w-[18px] h-[18px] text-[#2B7FFF] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                      @endif
                                 </div>
                                 <div class="min-w-0 flex-1 flex flex-col gap-0.5">
                                     <div class="flex items-center gap-2">
                                        <p class="text-[13.3px] text-stone-800 dark:text-[#F8FAFC] truncate">{{ $doc->original_name }}</p>
                                        @if($isLoading)
                                            <span class="inline-flex items-center gap-1 text-[10px] text-ista-primary dark:text-[#A5B4FC]">
                                                <span class="h-1.5 w-1.5 rounded-full bg-current animate-ping"></span>
                                                Uploading
                                            </span>
                                        @endif
                                     </div>
                                     <p class="text-[11.4px] text-[#64748B] dark:text-[#94A3B8]">{{ $size }} @if($isLoading) • Processing... @endif</p>
                                 </div>

                                 <button type="button" wire:click.prevent="deleteDocument({{ $doc->id }})" wire:confirm="Delete this file from your documents?" class="h-7 w-7 rounded-md text-[#94A3B8] hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors flex items-center justify-center" title="Remove">
                                     <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                     </svg>
                                 </button>
                             </label>
                          @endforeach
                     </div>
                 @else
                     <p class="text-[12px] text-gray-400 mt-6 px-1">No documents available</p>
                 @endif
             </div>
        </div>
    </aside>

    <!-- Unified Mobile Backdrop: closes whichever sidebar is open -->
    <div 
        x-show="isMobile && (showLeftSidebar || showRightSidebar)" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="showLeftSidebar = false; showRightSidebar = false;"
        class="fixed inset-0 bg-black/50 z-40"
        style="display:none;"
    ></div>

</div>
