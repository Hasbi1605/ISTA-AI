<div x-data="{ 
        showLeftSidebar: true,
        showRightSidebar: true,
        isDraggingFile: false,
        dragDepth: 0,
        dropError: '',
        sendError: '',
        messageAcked: false,
        promptDraft: '',
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
        }
     }"
     x-on:dragenter.window.prevent="onDragEnter($event)"
     x-on:dragover.window.prevent="onDragOver($event)"
     x-on:dragleave.window.prevent="onDragLeave($event)"
     x-on:drop.window.prevent="onDropFile($event)"
    x-init="initChatBehavior()"
     class="flex h-screen w-full overflow-hidden bg-white dark:bg-[#020618] text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">
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
        :class="showLeftSidebar ? 'w-[288px] opacity-100 translate-x-0 border-r border-[#E2E8F0] dark:border-[#1E293B]' : 'w-0 opacity-0 -translate-x-3 border-r border-transparent pointer-events-none'"
        class="h-full flex-shrink-0 overflow-hidden bg-[#F8FAFC]/50 dark:bg-[#0F172B]/50 backdrop-blur-[10px] flex flex-col z-10 transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]">
        
        <!-- New Chat Button -->
        <div class="p-4 pt-5 pb-5">
            <button wire:click="startNewChat" class="w-full flex items-center justify-start px-4 py-2.5 rounded-lg border border-[#E2E8F0] dark:border-[#334155] dark:bg-transparent bg-white hover:bg-gray-50 dark:hover:bg-white/5 font-medium text-[13px] text-gray-700 dark:text-gray-200 transition-all duration-200 shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-[#64748B] dark:text-[#CBD5E1]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                            <button wire:click="loadConversation({{ $conversation->id }})" 
                               class="w-full text-left px-3 py-2 rounded-md flex items-center transition-colors duration-200 {{ $currentConversationId == $conversation->id ? 'bg-[#E2E8F0] dark:bg-[#1E293B] text-[#0F172A] dark:text-[#F8FAFC] font-medium' : 'hover:bg-black/5 dark:hover:bg-white/5 text-[#334155] dark:text-[#CBD5E1]' }}">
                               <!-- Chat icon -->
                                         <img src="{{ $uiIcons['historyLight'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 dark:hidden" />
                                         <img src="{{ $uiIcons['historyDark'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 hidden dark:block" />
                               <span class="truncate text-[13.2px]" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                            </button>
                            <button wire:click="deleteConversation({{ $conversation->id }})"
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
                <button wire:click="toggleOlderChats" class="flex items-center justify-between w-full text-left">
                    <h3 class="text-[11.3px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2">Previous 7 Days</h3>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-[#64748B] dark:text-[#94A3B8] transition-transform duration-200 {{ $showOlderChats ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                @if($showOlderChats)
                    <ul class="mt-1 space-y-1">
                        @foreach($olderChats as $conversation)
                            <li class="group relative">
                                <button wire:click="loadConversation({{ $conversation->id }})" 
                                   class="w-full text-left px-3 py-2 rounded-md flex items-center transition-colors duration-200 {{ $currentConversationId == $conversation->id ? 'bg-[#E2E8F0] dark:bg-[#1E293B] text-[#0F172A] dark:text-[#F8FAFC] font-medium' : 'hover:bg-black/5 dark:hover:bg-white/5 text-[#334155] dark:text-[#CBD5E1]' }}">
                                    <!-- Chat icon -->
                                    <img src="{{ $uiIcons['historyLight'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 dark:hidden" />
                                    <img src="{{ $uiIcons['historyDark'] }}" alt="" class="h-4 w-4 mr-2.5 flex-shrink-0 hidden dark:block" />
                                    <span class="truncate text-[13.2px]" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                                </button>
                                <button wire:click="deleteConversation({{ $conversation->id }})"
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
                <div class="h-8 w-8 rounded bg-indigo-600 flex items-center justify-center text-white shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 2a8 8 0 100 16 8 8 0 000-16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <h4 class="text-[13.1px] font-medium leading-tight">Settings</h4>
                    <p class="text-[11.3px] text-gray-500 dark:text-gray-400">More models & features</p>
                </div>
             </a>
        </div>
    </aside>

    <!-- CENTER MAIN: Chat Area -->
    <main class="flex-1 flex flex-col relative w-full h-full bg-transparent z-0 overflow-hidden">
        
        <!-- Header for Chat Space -->
        <div class="h-[61px] flex-shrink-0 flex items-center justify-between px-6 z-20 border-b border-[#E2E8F0]/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
            <!-- Left toggler and title -->
            <div class="flex items-center gap-4">
                <button @click="showLeftSidebar = !showLeftSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-[#1D293D] transition-colors">
                    <img src="{{ $uiIcons['collapseLeftLight'] }}" alt="" class="h-5 w-5 dark:hidden transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                    <img src="{{ $uiIcons['collapseLeftDark'] }}" alt="" class="h-5 w-5 hidden dark:block transition-transform duration-300 ease-in-out" :class="showLeftSidebar ? 'rotate-0' : 'rotate-180'" />
                </button>
                <h2 class="text-[17px] font-bold tracking-tight text-[#0F172A] dark:text-[#F8FAFC]">ISTA AI</h2>
            </div>

            <!-- Right toggles -->
            <div class="flex items-center gap-3">
                <!-- Theme Toggle Button -->
                <button @click="darkMode = !darkMode" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-[#1D293D] transition-colors">
                    <svg x-show="!darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#64748B]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                    </svg>
                    <svg x-show="darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#CBD5E1]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                    </svg>
                </button>

                <button @click="showRightSidebar = !showRightSidebar" class="p-2 rounded-[10px] hover:bg-[#F1F5F9] dark:hover:bg-[#1D293D] transition-colors">
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
        <div class="flex-1 overflow-y-auto px-6 py-8 space-y-8" x-ref="chatBox" x-on:message-streamed.window="$refs.chatBox.scrollTop = $refs.chatBox.scrollHeight">
            @if(empty($messages))
                <div class="h-full flex flex-col items-center justify-center text-center">
                    <div class="h-12 w-12 rounded-full border border-gray-200 dark:border-[#334155] flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
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
                    <div class="w-full sm:max-w-3xl flex items-start gap-4 px-2 sm:px-8 {{ $isUserMessage ? 'flex-row-reverse' : '' }}">
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center {{ $message['role'] == 'user' ? 'bg-[#E2E8F0] dark:bg-[#1D293D] text-[#62748E] dark:text-[#90A1B9]' : 'bg-[#615FFF] text-white' }}">
                            @if($message['role'] == 'user')
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3l1.2 2.9L16 7.1l-2.8 1.2L12 11l-1.2-2.7L8 7.1l2.8-1.2L12 3zm6 9l.8 1.9L21 15l-2.2.9L18 18l-.8-2.1L15 15l2.2-1.1L18 12zM6 13l.7 1.6L8.4 15l-1.7.7L6 17.4l-.7-1.7L3.6 15l1.7-.4L6 13z" />
                                </svg>
                            @endif
                        </div>

                        <div class="flex flex-col gap-1 w-full {{ $isUserMessage ? 'items-end text-right' : 'items-start text-left' }}">
                            @php
                                $messageTime = !empty($message['created_at'])
                                    ? \Illuminate\Support\Carbon::parse($message['created_at'])->timezone('Asia/Jakarta')->format('H:i') . ' WIB'
                                    : null;
                            @endphp
                            <div class="flex items-center gap-2 mb-1 {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                                <span class="text-[13px] font-bold text-[#0F172A] dark:text-[#F8FAFC]">{{ $message['role'] == 'user' ? 'You' : 'ISTA AI' }}</span>
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
                                        class="rounded-xl bg-[#F8FAFC] dark:bg-[#0F172A] border border-[#E2E8F0] dark:border-[#1D293D] px-4 py-3 text-[14.5px] leading-relaxed text-[#334155] dark:text-[#CAD5E2] max-w-[656px] prose dark:prose-invert prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-[#0F172A] dark:prose-li:marker:text-[#F8FAFC] pb-1"
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
                                        class="rounded-xl bg-[#F8FAFC] dark:bg-[#0F172A] border border-[#E2E8F0] dark:border-[#1D293D] px-4 py-3 text-[14.5px] leading-relaxed text-[#334155] dark:text-[#CAD5E2] max-w-[656px] prose dark:prose-invert prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-[#0F172A] dark:prose-li:marker:text-[#F8FAFC] pb-1"
                                        x-html="@js((string) $assistantHtml)"
                                    >
                                    </div>
                                @endif
                            @else
                                <div class="text-[14.5px] leading-relaxed text-[#334155] dark:text-[#CAD5E2] max-w-[656px]">
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
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center bg-[#E2E8F0] dark:bg-[#1D293D] text-[#62748E] dark:text-[#90A1B9]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                        <div class="flex flex-col gap-1 w-full items-end text-right">
                            <div class="flex items-center gap-2 mb-1 justify-end">
                                <span class="text-[13px] font-bold text-[#0F172A] dark:text-[#F8FAFC]">You</span>
                            </div>
                            <div class="text-[14.5px] leading-relaxed text-[#334155] dark:text-[#CAD5E2] max-w-[656px]">
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
                  "
                 class="flex justify-start"
                 x-show="streaming">
                  <div class="w-full sm:max-w-3xl flex flex-row items-start gap-4 px-2 sm:px-8">
                     <div class="shrink-0 h-8 w-8 rounded-full bg-[#615FFF] text-white flex items-center justify-center">
                         <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3l1.2 2.9L16 7.1l-2.8 1.2L12 11l-1.2-2.7L8 7.1l2.8-1.2L12 3zm6 9l.8 1.9L21 15l-2.2.9L18 18l-.8-2.1L15 15l2.2-1.1L18 12zM6 13l.7 1.6L8.4 15l-1.7.7L6 17.4l-.7-1.7L3.6 15l1.7-.4L6 13z" />
                         </svg>
                     </div>
                     <div class="flex flex-col gap-1 items-start w-full">
                         <div class="flex items-center gap-2 mb-1">
                             <span class="text-[13px] font-bold text-[#0F172A] dark:text-[#F8FAFC]">ISTA AI</span>
                             <span x-show="modelName" class="text-[10px] bg-[#E2E8F0] dark:bg-[#1E293B] px-1.5 py-0.5 rounded text-gray-600 dark:text-gray-300" x-text="modelName"></span>
                         </div>
                         <div class="rounded-xl bg-[#F8FAFC] dark:bg-[#0F172A] border border-[#E2E8F0] dark:border-[#1D293D] text-[14.5px] leading-relaxed text-[#334155] dark:text-[#CAD5E2]"
                              :class="text === '' ? 'inline-flex items-center px-4 py-3 w-auto' : 'px-4 py-3 w-full max-w-[656px]'">
                             <div x-show="text === ''" class="flex space-x-1.5 py-1">
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce"></div>
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce [animation-delay:-0.15s]"></div>
                                <div class="h-2 w-2 bg-gray-400 dark:bg-[#64748B] rounded-full animate-bounce [animation-delay:-0.3s]"></div>
                             </div>
                             <p x-show="text !== ''" class="whitespace-pre-wrap" x-text="text"></p>
                         </div>
                     </div>
                  </div>
            </div>
        </div>

        <!-- Input Area container -->
        <div class="px-6 pb-6 pt-2 bg-white dark:bg-[#020618] w-full">
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
            <form x-on:submit.prevent="submitPrompt($event)" class="max-w-3xl mx-auto relative rounded-xl shadow-sm bg-white dark:bg-[#0F172A] border border-[#E2E8F0] dark:border-[#1E293B] focus-within:border-indigo-500 dark:focus-within:border-indigo-500 transition-colors">
                <div class="flex flex-col w-full">
                    <div wire:loading.flex wire:target="chatAttachment" class="px-5 pt-4 items-center gap-2 text-[12px] text-[#4A4AF4] dark:text-[#8E81FF]">
                        <span class="h-2 w-2 rounded-full bg-current animate-ping"></span>
                    </div>

                    @if($isUploadingAttachment)
                        <div class="px-5 pt-4 flex items-center gap-2 text-[12px] text-[#4A4AF4] dark:text-[#8E81FF]">
                            <span class="h-2 w-2 rounded-full bg-current animate-pulse"></span>
                        </div>
                    @endif
                    @if($chatDocuments->count() > 0)
                        <div class="px-5 pt-5 pb-1 flex flex-wrap gap-3">
                            @foreach($chatDocuments as $doc)
                                <span class="inline-flex items-center gap-2 bg-[#E2E8F0] dark:bg-[#1D293D] text-[#314158] dark:text-[#CAD5E2] rounded-2xl px-4 py-2 text-[14px]">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4" />
                                    </svg>
                                    <span class="max-w-[180px] truncate">{{ $doc->original_name }}</span>
                                    <button type="button" wire:click="removeConversationDocument({{ $doc->id }})" class="text-[#7C8DA8] hover:text-[#314158] dark:hover:text-white" title="Remove">
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
                            <span class="inline-flex items-center gap-2 bg-[#EEF2FF] dark:bg-[#312E81]/30 text-[#4A4AF4] dark:text-[#C7D2FE] rounded-2xl px-4 py-2 text-[13px]">
                                <span class="w-4 h-4 rounded-full border-2 border-[#C7D2FE] border-t-[#4A4AF4] animate-spin"></span>
                                <span class="max-w-[190px] truncate">{{ $uploadingAttachmentName }}</span>
                            </span>
                        </div>
                    @endif
                    <div x-show="!isDraggingFile" class="flex items-end px-3 pb-3 pt-3 w-full">
                        <textarea 
                            x-model="promptDraft"
                            x-on:keydown.enter.prevent="if($event.shiftKey) return; submitPrompt($event)"
                            placeholder="Message ISTA AI..." 
                            class="flex-1 max-h-[200px] min-h-[44px] bg-transparent border-none focus:ring-0 resize-none text-[14.5px] text-[#0F172A] dark:text-[#F8FAFC] placeholder-[#94A3B8] dark:placeholder-[#64748B] outline-none px-2"
                            rows="1"
                        ></textarea>
                        
                        <div class="flex items-center gap-2 pl-2">
                            <!-- Toggle Search -->
                            <button type="button" wire:click="toggleWebSearch" class="h-[30px] px-[13px] border border-transparent rounded-full text-[11.4px] font-normal flex items-center gap-[6px] transition-colors {{ $webSearchMode ? 'bg-[#E2E8F0] text-[#334155] dark:bg-[#314158] dark:text-[#E2E8F0]' : 'bg-[#F1F5F9] text-[#62748E] dark:bg-[#1D293D] dark:text-[#62748E]' }}">
                                <img src="{{ $uiIcons['searchLight'] }}" alt="" class="w-[14px] h-[14px] dark:hidden" />
                                <img src="{{ $uiIcons['searchDark'] }}" alt="" class="w-[14px] h-[14px] hidden dark:block" />
                                <span>Search</span>
                            </button>

                            <button type="button" @click="openAttachmentPicker()" wire:loading.attr="disabled" wire:target="chatAttachment" class="h-[34px] w-[34px] rounded-full transition-colors flex items-center justify-center hover:bg-[#F1F5F9] dark:hover:bg-[#1D293D] disabled:opacity-60" title="Attach file">
                                <img src="{{ $uiIcons['uploadLight'] }}" alt="" class="h-[18px] w-[18px] dark:hidden" />
                                <img src="{{ $uiIcons['uploadDark'] }}" alt="" class="h-[18px] w-[18px] hidden dark:block" />
                            </button>

                            <!-- Send -->
                            <button type="submit" 
                                    :disabled="isSendingMessage"
                                    class="bg-[#F1F5F9] dark:bg-[#1D293D] hover:bg-[#E2E8F0] dark:hover:bg-[#314158] disabled:opacity-50 rounded-full transition-colors h-[32px] w-[32px] flex items-center justify-center">
                                <img src="{{ $uiIcons['sendLight'] }}" alt="" class="h-[17px] w-[17px] dark:hidden" />
                                <img src="{{ $uiIcons['sendDark'] }}" alt="" class="h-[17px] w-[17px] hidden dark:block" />
                            </button>
                        </div>
                    </div>

                    <div x-show="isDraggingFile" x-transition.opacity class="px-3 pb-3 pt-3 w-full">
                        <div class="h-[84px] w-full rounded-xl border-2 border-dashed border-[#818CFF] dark:border-[#8E81FF] bg-[#EEF2FF] dark:bg-[#312E81]/20 flex items-center justify-center gap-2 text-[13px] font-semibold text-[#4A4AF4] dark:text-[#A5B4FC]">
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
        :class="showRightSidebar ? 'w-[288px] opacity-100 translate-x-0 border-l border-[#E2E8F0] dark:border-[#1E293B]' : 'w-0 opacity-0 translate-x-3 border-l border-transparent pointer-events-none'"
        class="h-full flex-shrink-0 overflow-hidden bg-[#F8FAFC]/50 dark:bg-[#0F172B]/50 backdrop-blur-[10px] flex flex-col z-10 transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]">
        
        <div class="h-[53px] px-4 flex items-center">
            <h3 class="text-[13.8px] font-bold text-[#0F172A] dark:text-[#F8FAFC] flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                Semua Dokumen Saya
            </h3>
        </div>
        
        <div class="flex-1 overflow-y-auto px-4 pt-4" @if($hasDocumentsInProgress) wire:poll.3s="loadAvailableDocuments" @else wire:poll.20s="loadAvailableDocuments" @endif>
             <div class="mb-4">
                 <h4 class="text-[12px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-3">Active Files</h4>
                 @php
                     $readyDocumentIds = $availableDocuments->where('status', 'ready')->pluck('id')->map(fn ($id) => (int) $id)->toArray();
                     $selectedIds = array_map('intval', $selectedDocuments);
                     $selectedInAvailableCount = count(array_intersect($selectedIds, $readyDocumentIds));
                     $allDocumentsSelected = count($readyDocumentIds) > 0 && $selectedInAvailableCount === count($readyDocumentIds);
                 @endphp
                 <div class="flex items-center flex-nowrap gap-0.5 mb-4 px-1 pb-3 border-b border-[#E2E8F0]/70 dark:border-[#1D293D]/70">
                     <button type="button" wire:click="toggleSelectAllDocuments" aria-pressed="{{ $allDocumentsSelected ? 'true' : 'false' }}" class="inline-flex items-center gap-1.5 text-[#62748E] dark:text-[#90A1B9] hover:text-[#314158] dark:hover:text-white text-[11px] leading-[1.1] font-semibold px-1.5 py-1 rounded-md hover:bg-[#F1F5F9] dark:hover:bg-[#1D293D] transition-colors whitespace-nowrap">
                         @if($allDocumentsSelected)
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#615FFF]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                         <button type="button" wire:click="addSelectedDocumentsToChat" class="inline-flex shrink-0 items-center gap-1 text-[#4A4AF4] dark:text-[#8E81FF] text-[10.5px] font-semibold px-1.5 py-1 rounded-md bg-[#4A4AF4]/10 dark:bg-[#4A4AF4]/20 hover:bg-[#4A4AF4]/15 dark:hover:bg-[#4A4AF4]/30 transition-colors whitespace-nowrap">
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
                                 {{ $isSelected ? 'bg-white/95 dark:bg-[#1D293D] border-[#818CFF] dark:border-[#818CFF] shadow-[0_1px_4px_rgba(97,95,255,0.25)]' : 'bg-white dark:bg-transparent border-[#E2E8F0] dark:border-[#1E293B] hover:border-[#CBD5E1] dark:hover:border-[#334155]' }} {{ $isLoading ? 'animate-pulse' : '' }}">
                                 @if($isLoading)
                                     <div class="w-3.5 h-3.5 rounded-full border-2 border-[#CBD5E1] dark:border-[#334155] border-t-[#615FFF] dark:border-t-[#8E81FF] animate-spin"></div>
                                 @else
                                     <input type="checkbox" wire:model.live="selectedDocuments" value="{{ $doc->id }}"
                                         class="rounded text-indigo-600 focus:ring-indigo-500 bg-white dark:bg-transparent border-[#CBD5E1] dark:border-[#64748B] w-3.5 h-3.5 cursor-pointer aspect-square"
                                         {{ $isReady ? '' : 'disabled' }}>
                                 @endif
                                 <div class="h-[34px] w-[34px] rounded-lg bg-[#F8FAFC] dark:bg-[#0F172A] border border-[#E2E8F0] dark:border-[#334155] flex items-center justify-center">
                                     @if($ext === 'pdf')
                                         <svg class="w-[18px] h-[18px] text-[#FF2056] shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path></svg>
                                     @elseif($ext === 'txt')
                                         <svg class="w-[18px] h-[18px] text-[#62748E] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h6" /></svg>
                                     @elseif(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'img']))
                                         <svg class="w-[18px] h-[18px] text-[#FD9A00] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm4 4h.01M21 15l-5-5-7 7-3-3-3 3" /></svg>
                                     @else
                                         <svg class="w-[18px] h-[18px] text-[#2B7FFF] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                     @endif
                                 </div>
                                 <div class="min-w-0 flex-1 flex flex-col gap-0.5">
                                     <div class="flex items-center gap-2">
                                        <p class="text-[13.3px] text-[#0F172A] dark:text-[#F8FAFC] truncate">{{ $doc->original_name }}</p>
                                        @if($isLoading)
                                            <span class="inline-flex items-center gap-1 text-[10px] text-[#615FFF] dark:text-[#A5B4FC]">
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

</div>
