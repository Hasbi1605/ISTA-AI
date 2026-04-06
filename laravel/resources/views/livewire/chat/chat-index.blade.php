<div class="flex h-[calc(100vh-64px)] overflow-hidden bg-gray-50 dark:bg-gray-900">
    <!-- Sidebar for History -->
    <aside class="w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col">
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
                @foreach($conversations as $conversation)
                    <li>
                        <button wire:click="loadConversation({{ $conversation->id }})" 
                           class="w-full text-left p-3 rounded-lg flex items-center transition duration-150 {{ $currentConversationId == $conversation->id ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600' : 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                            <span class="truncate text-sm">{{ $conversation->title }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </aside>

    <!-- Main Chat Area -->
    <main class="flex-1 flex flex-col relative">
        <!-- Messages List -->
        <div class="flex-1 overflow-y-auto p-4 space-y-6" x-ref="chatBox" x-on:message-streamed.window="$refs.chatBox.scrollTop = $refs.chatBox.scrollHeight">
            @if(empty($messages))
                <div class="h-full flex items-center justify-center text-center">
                    <div class="max-w-md">
                        <x-application-logo class="h-16 w-16 mx-auto mb-4 opacity-50 fill-current text-gray-400" />
                        <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100">Selamat Datang di ISTA AI</h2>
                        <p class="mt-2 text-gray-500">Mulai percakapan cerdas dengan asisten virtual istana. Tanyakan tentang prosedur, bantuan penulisan, atau ringkasan dokumen.</p>
                    </div>
                </div>
            @endif

            @foreach($messages as $message)
                <div class="flex {{ $message['role'] == 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[80%] flex {{ $message['role'] == 'user' ? 'flex-row-reverse' : 'flex-row' }} items-start">
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
            <div x-data="{ streaming: false, text: '', modelName: '' }" 
                 x-on:message-send.window="streaming = true; text = ''; modelName = ''"
                 x-init="
                   $wire.on('assistant-output', (data) => {
                       text += data[0];
                       streaming = true;
                   });
                   $wire.on('model-name', (data) => {
                       modelName = data[0];
                   });
                 "
                 class="flex justify-start"
                 x-show="streaming">
                 <div class="max-w-[80%] flex flex-row items-start">
                    <div class="shrink-0 h-8 w-8 rounded-full bg-blue-500 mr-3 flex items-center justify-center">
                        <span class="text-xs text-white font-bold">AI</span>
                    </div>
                    <div class="relative px-4 py-3 rounded-2xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 shadow-sm border border-gray-100 dark:border-gray-700 rounded-tl-none">
                        <span x-show="modelName" class="inline-block px-2 py-0.5 mb-2 text-[10px] font-semibold rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300" x-text="modelName"></span>
                        <p class="text-sm leading-relaxed whitespace-pre-wrap" id="ai-streaming-buffer" wire:stream="assistant-output"></p>
                    </div>
                 </div>
            </div>

            <!-- Loading Indicator -->
            <div wire:loading wire:target="sendMessage" class="flex justify-start">
                <div class="max-w-[80%] flex flex-row items-center">
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
            <form wire:submit.prevent="sendMessage" class="max-w-4xl mx-auto flex items-end space-x-3">
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
            <p class="mt-2 text-center text-xs text-gray-500">ISTA AI dapat memberikan informasi yang kurang akurat. Periksa kembali informasi penting.</p>
        </div>
    </main>
</div>
