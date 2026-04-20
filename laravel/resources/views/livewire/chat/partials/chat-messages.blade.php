<div x-data="chatMessages" class="flex-1 overflow-y-auto px-3 sm:px-6 py-6 sm:py-8 space-y-6 sm:space-y-8" x-ref="chatBox" x-on:message-streamed.window="scrollToBottom()">
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
    @else

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
    @endif

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

    <div x-data="{ streaming: false, text: '', modelName: '', sources: [] }"
         x-on:message-send.window="streaming = true; text = ''; modelName = ''; sources = ''"
          x-on:message-complete.window="streaming = false; text = ''; modelName = ''; sources = ''"
         x-init="
            $wire.on('assistant-output', (data) => { text += data[0]; streaming = true; });
            $wire.on('model-name', (data) => { modelName = data[0]; });
            $wire.on('assistant-sources', (data) => { sources = data[0]; });
                  $wire.on('assistant-message-persisted', () => { streaming = false; text = ''; modelName = ''; sources = ''; });
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