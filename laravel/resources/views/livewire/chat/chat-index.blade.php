<div x-data="chatLayout" 
     class="flex h-screen w-full overflow-hidden text-stone-800 dark:text-gray-100 font-sans transition-colors duration-300 relative ista-display-sans bg-stone-50/50 dark:bg-gray-900" style="background-image: url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: 8px 8px;"
>
    <!-- Overlay for Global File Drag & Drop -->
    <div x-data="chatFileHandler"
         x-on:dragenter.window.prevent="onDragEnter($event)"
         x-on:dragover.window.prevent="onDragOver($event)"
         x-on:dragleave.window.prevent="onDragLeave($event)"
         x-on:drop.window.prevent="onDropFile($event)"
         class="contents"
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

        <!-- LEFT SIDEBAR: Chat History -->
        @include('livewire.chat.partials.chat-left-sidebar')

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

            <!-- Messages List -->
            @include('livewire.chat.partials.chat-messages')

            <!-- Input Area (Composer) -->
            @include('livewire.chat.partials.chat-composer', ['prompt' => $prompt])

        </main>

        <!-- RIGHT SIDEBAR: Documents -->
        @include('livewire.chat.partials.chat-right-sidebar')

        <!-- Drag & Drop Overlay Visual -->
        <div x-show="isDraggingFile" x-transition.opacity class="fixed inset-0 z-[60] bg-ista-primary/10 backdrop-blur-[2px] flex items-center justify-center pointer-events-none">
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

    <!-- Alpine Data Definitions -->
    <script>
        document.addEventListener('alpine:init', () => {
            // 1. Layout Management
            Alpine.data('chatLayout', () => ({
                darkMode: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
                isMobile: window.matchMedia('(max-width: 1023px)').matches,
                showLeftSidebar: !window.matchMedia('(max-width: 1023px)').matches,
                showRightSidebar: !window.matchMedia('(max-width: 1023px)').matches,

                init() {
                    this.$watch('darkMode', val => {
                        localStorage.setItem('theme', val ? 'dark' : 'light');
                        document.documentElement.classList.toggle('dark', val);
                    });
                    document.documentElement.classList.toggle('dark', this.darkMode);

                    // Responsive handling
                    const mql = window.matchMedia('(max-width: 1023px)');
                    const handleMqlChange = (e) => {
                        this.isMobile = e.matches;
                        if (!this.isMobile) {
                            this.showLeftSidebar = true;
                            this.showRightSidebar = true;
                        } else {
                            this.showLeftSidebar = false;
                            this.showRightSidebar = false;
                        }
                    };
                    mql.addEventListener('change', handleMqlChange);
                },

                // Event Listeners for cross-component sidebar control
                ['x-on:open-sidebar-right.window']() {
                    this.showRightSidebar = true;
                }
            }));

            // 2. Message List & Scroll Management
            Alpine.data('chatMessages', () => ({
                optimisticUserMessage: '',
                
                init() {
                    this.scrollToBottom();
                    
                    const chatBox = this.$refs.chatBox;
                    if (chatBox) {
                        const observer = new MutationObserver(() => this.scrollToBottom());
                        observer.observe(chatBox, { childList: true, subtree: true, characterData: true });
                    }

                    this.$wire.on('assistant-output', () => this.scrollToBottom());
                    this.$wire.on('user-message-acked', () => {
                        this.optimisticUserMessage = '';
                        this.scrollToBottom();
                    });
                },

                scrollToBottom(smooth = false) {
                    this.$nextTick(() => {
                        const chatBox = this.$refs.chatBox;
                        if (!chatBox) return;
                        chatBox.scrollTo({
                            top: chatBox.scrollHeight,
                            behavior: smooth ? 'smooth' : 'auto',
                        });
                    });
                },

                // Event Listeners for cross-component communication
                ['x-on:message-send.window']() {
                    this.optimisticUserMessage = event.detail.text;
                    this.scrollToBottom();
                }
            }));

            // 3. Composer & Input Management
            Alpine.data('chatComposer', (config) => ({
                promptDraft: config.prompt || '',
                isSendingMessage: false,
                sendError: '',
                messageAcked: false,

                init() {
                    if (this.promptDraft) {
                        setTimeout(() => this.submitPrompt(), 100);
                    }
                    this.$wire.on('user-message-acked', () => {
                        this.messageAcked = true;
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
                    
                    // Dispatch to notify Messages component
                    this.$dispatch('message-send', { text: text });
                    
                    this.promptDraft = '';
                    this.autoResizeTextarea(this.$refs.chatInput);

                    this.$wire.sendMessage(text)
                        .catch((error) => {
                            this.$dispatch('user-message-acked'); // Clear optimistic if error
                            if (!this.messageAcked) {
                                this.promptDraft = text;
                                this.sendError = 'Pesan gagal dikirim. Periksa koneksi.';
                            } else {
                                this.sendError = 'Jawaban gagal diproses. Coba kirim ulang.';
                            }
                            setTimeout(() => this.sendError = '', 6000);
                        })
                        .finally(() => {
                            this.isSendingMessage = false;
                            this.$dispatch('message-complete');
                        });
                },

                openAttachmentPicker() {
                    this.$dispatch('open-sidebar-right');
                    const input = this.$refs.chatAttachmentInput;
                    if (input) {
                        input.value = '';
                        input.click();
                    }
                },

                autoResizeTextarea(el) {
                    if (!el) return;
                    el.style.height = 'auto';
                    el.style.height = Math.min(Math.max(el.scrollHeight, 44), 200) + 'px';
                    el.style.overflowY = el.scrollHeight > 200 ? 'auto' : 'hidden';
                }
            }));

            // 4. File Handler Logic
            Alpine.data('chatFileHandler', () => ({
                isDraggingFile: false,
                dragDepth: 0,
                dropError: '',

                onDragEnter(event) {
                    if (!this.hasFiles(event)) return;
                    this.dragDepth++;
                    this.isDraggingFile = true;
                },
                onDragOver(event) {
                    if (!this.hasFiles(event)) return;
                    this.isDraggingFile = true;
                },
                onDragLeave(event) {
                    if (!this.hasFiles(event)) return;
                    this.dragDepth = Math.max(this.dragDepth - 1, 0);
                    if (this.dragDepth === 0) this.isDraggingFile = false;
                },
                onDropFile(event) {
                    this.dragDepth = 0;
                    this.isDraggingFile = false;
                    const files = event.dataTransfer?.files;
                    if (!files || files.length === 0) return;
                    
                    if (files.length > 1) {
                        this.showError('Hanya bisa upload 1 file sekaligus.');
                        return;
                    }

                    // Dispatch directly to the hidden input in composer
                    const input = document.querySelector('[x-ref="chatAttachmentInput"]');
                    if (input) {
                        input.files = files;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        this.$dispatch('open-sidebar-right');
                    }
                },
                hasFiles(event) {
                    return event.dataTransfer?.types?.includes('Files');
                },
                showError(msg) {
                    this.dropError = msg;
                    this.$dispatch('show-drop-error', { message: msg });
                    setTimeout(() => this.dropError = '', 3500);
                }
            }));
        });
    </script>
</div>
