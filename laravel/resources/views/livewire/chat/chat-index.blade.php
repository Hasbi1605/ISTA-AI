<div class="flex h-[calc(100vh-64px)] overflow-hidden bg-gray-50 dark:bg-gray-900">
    <!-- Sidebar for History -->
    <aside class="w-52 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col overflow-y-auto">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <button wire:click="startNewChat" class="w-full flex items-center justify-center p-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition duration-150">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Chat Baru
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto">
            <h3 class="p-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">Riwayat Chat</h3>
            <ul class="space-y-1 px-2">
                @php
                    $visibleChats = $conversations->take(10);
                    $olderChats = $conversations->skip(10);
                @endphp
                
                @foreach($visibleChats as $conversation)
                    <li class="group relative">
                        <button wire:click="loadConversation({{ $conversation->id }})" 
                           class="w-full text-left p-3 pr-8 rounded-lg flex items-center transition duration-150 {{ $currentConversationId == $conversation->id ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600' : 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                            <span class="truncate text-sm" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                        </button>
                        <!-- Delete button (visible on hover) -->
                        <button wire:click="deleteConversation({{ $conversation->id }})"
                                wire:confirm="Yakin ingin menghapus riwayat chat ini?"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/30 text-gray-400 hover:text-red-500 transition-all duration-150"
                                title="Hapus chat">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </li>
                @endforeach
                
                <!-- Older chats dropdown -->
                @if($olderChats->count() > 0)
                    <li class="pt-2">
                        <button wire:click="toggleOlderChats" 
                                class="w-full text-left p-2 rounded-lg flex items-center justify-between text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 transition duration-150">
                            <span class="text-xs font-medium">{{ $olderChats->count() }} chat lainnya</span>
                            <svg xmlns="http://www.w3.org/2000/svg" 
                                 class="h-4 w-4 transition-transform duration-200 {{ $showOlderChats ? 'rotate-180' : '' }}" 
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        
                        @if($showOlderChats)
                            <ul class="mt-1 space-y-1 border-l-2 border-gray-200 dark:border-gray-600 ml-2 pl-2">
                                @foreach($olderChats as $conversation)
                                    <li class="group relative">
                                        <button wire:click="loadConversation({{ $conversation->id }})" 
                                           class="w-full text-left p-2 pr-8 rounded-lg flex items-center transition duration-150 {{ $currentConversationId == $conversation->id ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600' : 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                            </svg>
                                            <span class="truncate text-xs" title="{{ $conversation->title }}">{{ $conversation->title }}</span>
                                        </button>
                                        <!-- Delete button -->
                                        <button wire:click="deleteConversation({{ $conversation->id }})"
                                                wire:confirm="Yakin ingin menghapus riwayat chat ini?"
                                                class="absolute right-1 top-1/2 -translate-y-1/2 p-1 rounded opacity-0 group-hover:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/30 text-gray-400 hover:text-red-500 transition-all duration-150"
                                                title="Hapus chat">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endif
            </ul>
        </div>
    </aside>

    <!-- Main Chat Area -->
    <main class="flex-1 flex flex-col relative">
        <!-- Document Selector (collapsible) -->
        @if($showDocumentSelector)
            <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Pilih Dokumen untuk Chat</h3>
                    <div class="flex gap-2">
                        <button wire:click="selectAllDocuments" class="text-xs text-blue-600 hover:text-blue-800">Pilih Semua</button>
                        <span class="text-gray-300">|</span>
                        <button wire:click="clearDocumentSelection" class="text-xs text-gray-500 hover:text-gray-700">Hapus</button>
                        <span class="text-gray-300">|</span>
                        <button wire:click="toggleDocumentSelector" class="text-xs text-gray-500 hover:text-gray-700">Tutup</button>
                    </div>
                </div>
                
                @if(count($availableDocuments) > 0)
                    <div class="flex flex-wrap gap-2 max-h-32 overflow-y-auto">
                        @foreach($availableDocuments as $doc)
                            <label class="flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition
                                {{ in_array($doc->id, $selectedDocuments) ? 'bg-blue-100 dark:bg-blue-900/30 border-blue-500' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600' }}">
                                <input type="checkbox" wire:change="toggleDocument({{ $doc->id }})" 
                                    {{ in_array($doc->id, $selectedDocuments) ? 'checked' : '' }}
                                    class="rounded text-blue-600">
                                <span class="text-sm text-gray-700 dark:text-gray-300 truncate max-w-[200px]">{{ $doc->original_name }}</span>
                                <span class="text-xs text-gray-400">{{ $doc->status }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500">Tidak ada dokumen yang siap. Upload dokumen terlebih dahulu.</p>
                @endif
            </div>
        @endif

        <!-- Messages List -->
        <div class="flex-1 overflow-y-auto p-4 space-y-6" x-ref="chatBox" x-on:message-streamed.window="$refs.chatBox.scrollTop = $refs.chatBox.scrollHeight">
            @if(empty($messages))
                <div class="h-full flex items-center justify-center text-center px-4">
                    <div class="max-w-md space-y-4">
                        <x-application-logo class="h-20 w-20 mx-auto opacity-60 fill-current text-indigo-500 dark:text-indigo-400" />
                        <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Selamat Datang di ISTA AI</h2>
                        <p class="text-gray-600 dark:text-gray-400 text-base leading-relaxed">
                            Mulai percakapan cerdas dengan asisten virtual istana. Tanyakan tentang prosedur, bantuan penulisan, atau ringkasan dokumen.
                        </p>
                        <div class="flex flex-wrap gap-2 justify-center pt-4">
                            <span class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-full text-xs sm:text-sm">💡 Tanya prosedur</span>
                            <span class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-full text-xs sm:text-sm">📝 Bantuan penulisan</span>
                            <span class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-full text-xs sm:text-sm">📄 Ringkasan dokumen</span>
                        </div>
                    </div>
                </div>
            @endif

            @foreach($messages as $message)
                <div class="flex {{ $message['role'] == 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[90%] sm:max-w-xl flex {{ $message['role'] == 'user' ? 'flex-row-reverse' : 'flex-row' }} items-start">
                        <!-- Avatar placeholder -->
                        <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center {{ $message['role'] == 'user' ? 'bg-blue-600 ml-3' : 'bg-gray-400 dark:bg-gray-600 mr-3' }}">
                            <span class="text-xs text-white uppercase font-bold">{{ substr($message['role'], 0, 1) }}</span>
                        </div>
                        
                        <div class="relative px-4 py-3 rounded-2xl {{ $message['role'] == 'user' ? 'bg-blue-600 text-white rounded-tr-none' : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 shadow-sm border border-gray-100 dark:border-gray-700 rounded-tl-none' }}">
                            <p class="text-sm leading-relaxed whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach

            <!-- AI Streaming Output -->
            <div x-data="{ streaming: false, text: '', modelName: '', sources: [] }" 
                 x-on:message-send.window="streaming = true; text = ''; modelName = ''; sources = []"
                 x-init="
                    $wire.on('assistant-output', (data) => {
                        text += data[0];
                        streaming = true;
                    });
                    $wire.on('model-name', (data) => {
                        modelName = data[0];
                    });
                    $wire.on('assistant-sources', (data) => {
                        sources = data[0];
                    });
                  "
                 class="flex justify-start"
                 x-show="streaming">
                  <div class="max-w-[90%] sm:max-w-xl flex flex-row items-start">
                     <div class="shrink-0 h-8 w-8 rounded-full bg-blue-500 mr-3 flex items-center justify-center">
                         <span class="text-xs text-white font-bold">AI</span>
                     </div>
                     <div class="relative px-4 py-3 rounded-2xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 shadow-sm border border-gray-100 dark:border-gray-700 rounded-tl-none">
                         <span x-show="modelName" class="inline-block px-2 py-0.5 mb-2 text-[10px] font-semibold rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300" x-text="modelName"></span>
                         <p class="text-sm leading-relaxed whitespace-pre-wrap" id="ai-streaming-buffer" wire:stream="assistant-output"></p>
                         
                         <!-- Sources Display (Alpine.js reactive) -->
                         <div x-show="sources.length > 0" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                             <p class="text-xs font-semibold text-gray-500 mb-2">📚 Sumber Referensi:</p>
                             <ul class="space-y-1">
                                 <template x-for="(source, index) in sources" :key="index">
                                     <li class="text-xs text-gray-600 dark:text-gray-400">
                                         <span>📄 </span>
                                         <span x-text="source.filename"></span>
                                         <span x-show="source.relevance_score" class="text-gray-400">
                                             (relevansi: <span x-text="Math.round((1 - source.relevance_score) * 100) + '%'"></span>)
                                         </span>
                                     </li>
                                 </template>
                             </ul>
                         </div>
                     </div>
                  </div>
            </div>

            <!-- Loading Indicator -->
            <div wire:loading wire:target="sendMessage" class="flex justify-start">
                <div class="max-w-[90%] sm:max-w-xl flex flex-row items-center">
                    <div class="shrink-0 h-8 w-8 rounded-full bg-gray-400 dark:bg-gray-600 mr-3 flex items-center justify-center">
                        <span class="text-xs text-white font-bold">A</span>
                    </div>
                    <div class="flex space-x-1 p-2 bg-gray-100 dark:bg-gray-800 rounded-lg">
                        <div class="h-2 w-2 bg-gray-400 rounded-full animate-bounce"></div>
                        <div class="h-2 w-2 bg-gray-400 rounded-full animate-bounce [animation-delay:-0.15s]"></div>
                        <div class="h-2 w-2 bg-gray-400 rounded-full animate-bounce [animation-delay:-0.3s]"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-4 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
            <!-- Document Selection Button -->
            <div class="max-w-[90%] sm:max-w-xl mx-auto mb-3 flex items-center justify-between">
                <button wire:click="toggleDocumentSelector" 
                        class="flex items-center gap-2 text-sm {{ count($selectedDocuments) > 0 ? 'text-blue-600' : 'text-gray-500 hover:text-gray-700' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span>{{ count($selectedDocuments) > 0 ? count($selectedDocuments) . ' dokumen dipilih' : 'Pilih Dokumen' }}</span>
                </button>
                
                @if(count($selectedDocuments) > 0)
                    <span class="text-xs text-blue-600 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded">Mode RAG Aktif</span>
                @endif
            </div>
            
            <form wire:submit.prevent="sendMessage" class="max-w-[90%] sm:max-w-xl mx-auto flex items-end space-x-3">
                <div class="flex-1 relative">
                    <textarea 
                        wire:model="prompt"
                        x-on:keydown.enter.prevent="if($event.shiftKey) return; $wire.sendMessage(); $dispatch('message-send')"
                        placeholder="Ketik pesan untuk ISTA AI..." 
                        class="w-full pl-4 pr-12 py-3 bg-gray-100 dark:bg-gray-900 border-transparent focus:border-blue-500 focus:ring-blue-500 rounded-2xl resize-none max-h-32 text-gray-800 dark:text-gray-200 transition duration-150"
                        rows="1"
                    ></textarea>
                </div>
                <button type="submit" 
                        wire:loading.attr="disabled"
                        class="p-3 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-2xl transition duration-150">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                </button>
            </form>
            <p class="mt-3 text-center text-sm text-gray-600 dark:text-gray-400">ISTA AI dapat memberikan informasi yang kurang akurat. Periksa kembali informasi penting.</p>
        </div>
    </main>
</div>
