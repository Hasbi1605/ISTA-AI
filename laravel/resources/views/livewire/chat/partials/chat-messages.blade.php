@php
    $assistantAnswerBubbleClass = 'rounded-2xl rounded-bl-sm bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 px-4 py-3 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-stone-800 prose-a:text-sky-700 prose-a:decoration-sky-600/80 hover:prose-a:text-sky-800 dark:prose-headings:text-white dark:prose-p:text-gray-100 dark:prose-strong:text-white dark:prose-ul:text-gray-100 dark:prose-ol:text-gray-100 dark:prose-li:text-gray-100 dark:prose-li:marker:text-white dark:prose-a:text-sky-300 dark:prose-a:decoration-sky-300/90 dark:hover:prose-a:text-sky-200 pb-1';
    $assistantErrorBubbleClass = 'rounded-2xl rounded-bl-sm bg-rose-50/95 backdrop-blur-sm dark:bg-rose-950/30 border border-rose-200/80 dark:border-rose-500/30 px-4 py-3 text-[14.5px] leading-relaxed text-rose-900 dark:text-rose-100 prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-rose-700 prose-a:text-rose-700 prose-a:decoration-rose-500/80 hover:prose-a:text-rose-900 dark:prose-headings:text-rose-100 dark:prose-p:text-rose-100 dark:prose-strong:text-rose-50 dark:prose-ul:text-rose-100 dark:prose-ol:text-rose-100 dark:prose-li:text-rose-100 dark:prose-li:marker:text-rose-100 dark:prose-a:text-rose-200 dark:prose-a:decoration-rose-200/90 dark:hover:prose-a:text-white pb-1';
    $safeAssistantMarkdown = app(\App\Support\SafeAssistantMarkdown::class);
@endphp

<div x-data="chatMessages"
     class="mx-auto min-h-0 w-full max-w-4xl flex-1 overflow-y-auto px-3 py-5 sm:px-6 sm:py-7 space-y-6 sm:space-y-8"
     x-ref="chatBox"
     data-chat-box
     x-on:message-streamed.window="scrollToBottom()"
     x-on:message-send.window="optimisticUserMessage = $event.detail.text; isSwitchingConversation = false; startStreamingPlaceholder($event.detail.loadingContext || 'general'); scrollToBottom(false, true)"
     x-on:conversation-loading.window="isSwitchingConversation = true; optimisticUserMessage = ''; resetStreamingState()"
     x-on:conversation-loaded.window="isSwitchingConversation = false; resetStreamingState(); $nextTick(() => { maybeRestorePendingPlaceholder(); scrollToBottom(false, true); })"
     data-chat-messages-ready="true">
    <div class="hidden"
         data-chat-conversation-id="{{ $currentConversationId ?? '' }}"
         data-chat-last-message-role="{{ !empty($messages) ? ($messages[array_key_last($messages)]['role'] ?? '') : '' }}"
         data-chat-last-user-message-id="{{ collect($messages)->where('role', 'user')->last()['id'] ?? '' }}"
         data-chat-last-user-message-created-at="{{ collect($messages)->where('role', 'user')->last()['created_at'] ?? '' }}"
         data-chat-last-assistant-message-id="{{ collect($messages)->where('role', 'assistant')->last()['id'] ?? '' }}"
         aria-hidden="true"></div>
    @if(empty($messages))
        <div x-show="!optimisticUserMessage && !isSwitchingConversation" x-transition.opacity class="h-full flex flex-col items-center justify-center text-center">
            <div class="h-16 w-16 mb-6">
                <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
            </div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">ISTA AI</h2>
            <p class="text-gray-500 dark:text-[#94A3B8] text-[14px] max-w-md">
                Mulai percakapan baru untuk meminta ringkasan, informasi, atau bantuan kerja.
            </p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2 max-w-xl px-2">
                @foreach([
                    'Ringkas isi dokumen keputusan presiden terbaru',
                    'Buatkan poin-poin rapat internal singkat',
                    'Jelaskan prosedur tata naskah dinas resmi',
                    'Bantu susun draft memo undangan kegiatan',
                ] as $suggestion)
                    <button type="button"
                            @click="$dispatch('chat-apply-suggestion', { text: @js($suggestion) })"
                            class="rounded-full border border-stone-200/80 bg-white/90 px-3.5 py-1.5 text-[12px] font-medium text-stone-600 transition-colors hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-700 dark:bg-gray-900/80 dark:text-gray-300 dark:hover:text-amber-200">
                        {{ $suggestion }}
                    </button>
                @endforeach
            </div>
        </div>
    @else
    @if($messagesHasOlder)
        <div class="flex justify-center pb-2">
            <button type="button"
                    wire:click="loadOlderMessages"
                    wire:loading.attr="disabled"
                    wire:target="loadOlderMessages"
                    class="inline-flex items-center gap-2 rounded-full border border-stone-200/80 bg-white/90 px-4 py-1.5 text-[12px] font-semibold text-stone-600 transition-colors hover:border-ista-primary/30 hover:text-ista-primary disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900/80 dark:text-gray-300 dark:hover:text-amber-200">
                <span wire:loading.remove wire:target="loadOlderMessages">Muat pesan lebih lama</span>
                <span wire:loading.inline-flex wire:target="loadOlderMessages" class="items-center gap-2">
                    <span class="h-3 w-3 rounded-full border border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                    Memuat...
                </span>
            </button>
        </div>
    @endif

    @foreach($messages as $message)
        @php
            $isUserMessage = $message['role'] == 'user';
            $isAssistantError = ! $isUserMessage && (bool) ($message['is_error'] ?? false);
        @endphp
        @continue(! $isUserMessage && (int) ($message['id'] ?? 0) === (int) ($preservedStreamMessageId ?? 0))
        <div wire:key="chat-message-{{ $message['id'] }}" class="flex {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
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

                <div class="flex flex-col gap-1 min-w-0 {{ $isUserMessage ? 'max-w-[calc(100%-2.5rem)] items-end text-right' : 'w-full items-start text-left' }}">
                    @php
                        $messageTime = !empty($message['created_at'])
                            ? \Illuminate\Support\Carbon::parse($message['created_at'])->timezone('Asia/Jakarta')->format('H:i') . ' WIB'
                            : null;
                    @endphp
                    <div class="flex items-center gap-2 mb-1 {{ $isUserMessage ? 'justify-end' : 'justify-start' }}">
                        <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">{{ $message['role'] == 'user' ? 'Anda' : 'ISTA AI' }}</span>
                        @if($messageTime)
                            <span class="text-[10px] text-gray-400 dark:text-[#64748B]">{{ $messageTime }}</span>
                        @endif
                    </div>

                    @if($message['role'] == 'assistant')
                        @php
                            $assistantHtml = $safeAssistantMarkdown->toHtml((string) $message['content']);
                            $exportFileName = 'ista-ai-jawaban-' . $message['id'];
                            $assistantBubbleClass = $isAssistantError
                                ? $assistantErrorBubbleClass
                                : $assistantAnswerBubbleClass;
                        @endphp
                        <div
                            wire:key="chat-answer-actions-{{ $message['id'] }}"
                            data-answer-message-id="{{ $message['id'] }}"
                            x-data="chatAnswerActions({
                                messageId: @js((int) $message['id']),
                                html: @js((string) $assistantHtml),
                                exportUrl: @js(route('documents.export')),
                                exportFileName: @js($exportFileName),
                            })"
                            class="w-full max-w-[656px]"
                            >
                            @include('livewire.chat.partials.assistant-answer-bubble', [
                                'assistantBubbleClass' => $assistantBubbleClass,
                                'assistantHtml' => (string) $assistantHtml,
                                'content' => (string) $message['content'],
                                'isAssistantError' => $isAssistantError,
                                'messageId' => (int) $message['id'],
                                'shouldType' => (int) $message['id'] === (int) $newMessageId,
                            ])

                            @include('livewire.chat.partials.assistant-answer-actions')
                        </div>
                    @else
                        <div
                            wire:key="chat-user-message-{{ $message['id'] }}"
                            x-data="chatUserMessage({
                                messageId: @js((int) $message['id']),
                                content: @js((string) $message['content']),
                            })"
                            class="group relative max-w-[656px]"
                        >
                            <div x-show="!editing" class="inline-block w-fit max-w-[656px] min-w-0 rounded-2xl rounded-br-sm bg-ista-primary px-4 py-3 text-[14.5px] leading-relaxed text-white shadow-sm">
                                <p class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]" x-text="content"></p>
                            </div>
                            <div x-show="editing" x-cloak class="w-full max-w-[656px] rounded-2xl border border-stone-200/80 bg-white px-3 py-2 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                                <textarea
                                    x-ref="editInput"
                                    x-model="draft"
                                    x-on:keydown.enter="handleEditEnter($event)"
                                    class="w-full min-h-[44px] max-h-[200px] resize-none border-none bg-transparent text-[14.5px] text-stone-800 focus:outline-none focus:ring-0 dark:text-gray-100"
                                    rows="2"
                                ></textarea>
                                <div class="mt-2 flex items-center justify-end gap-2">
                                    <button type="button" @click="cancelEdit()" class="rounded-full px-3 py-1 text-[12px] font-medium text-stone-500 hover:text-stone-700 dark:text-gray-400 dark:hover:text-gray-200">Batal</button>
                                    <button type="button" @click="saveEdit()" :disabled="saving" class="rounded-full bg-ista-primary px-3 py-1 text-[12px] font-semibold text-white hover:bg-ista-dark disabled:opacity-60">Kirim ulang</button>
                                </div>
                                <p x-show="error" x-text="error" class="mt-1 text-[11px] text-rose-500"></p>
                            </div>
                            <button
                                type="button"
                                x-show="!editing"
                                @click="startEdit()"
                                title="Edit pesan"
                                aria-label="Edit pesan"
                                class="absolute -left-9 top-1/2 hidden -translate-y-1/2 rounded-lg p-1.5 text-stone-400 opacity-0 transition hover:bg-white/80 hover:text-stone-700 group-hover:opacity-100 dark:hover:bg-gray-800/80 dark:hover:text-gray-100 sm:inline-flex"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4 12.5-12.5z" />
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
    @endif

    <div x-show="isSwitchingConversation" x-transition.opacity class="px-2 sm:px-8" role="status" aria-live="polite" style="display: none;">
        <span class="sr-only">Memuat chat.</span>
        <div class="inline-flex items-center gap-1.5 rounded-full border border-stone-200/70 bg-white/75 px-3 py-2 text-stone-400 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/75 dark:text-gray-500" aria-hidden="true">
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.2s]"></span>
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.1s]"></span>
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-current"></span>
        </div>
    </div>

    <template x-if="optimisticUserMessage">
        <div class="flex justify-end">
            <div class="w-full sm:max-w-3xl flex items-start gap-4 px-2 sm:px-8 flex-row-reverse">
                <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center bg-[#E2E8F0] dark:bg-white text-[#62748E] dark:text-black">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m12-10a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="flex max-w-[calc(100%-2.5rem)] flex-col gap-1 items-end text-right">
                    <div class="flex items-center gap-2 mb-1 justify-end">
                        <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">Anda</span>
                    </div>
                    <div class="inline-block w-fit max-w-[656px] min-w-0 rounded-2xl rounded-br-sm bg-ista-primary px-4 py-3 text-[14.5px] leading-relaxed text-white shadow-sm">
                        <p class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]" x-text="optimisticUserMessage"></p>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <div
        class="flex justify-start"
        x-show="streaming"
        x-ref="streamingMessage">
        <div class="w-full sm:max-w-3xl flex items-start gap-2 sm:gap-4 px-0 sm:px-8">
            <div class="shrink-0 h-8 w-8 rounded-full flex items-center justify-center bg-white border border-stone-200 shadow-sm p-1">
                <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
            </div>
            <div class="flex flex-col gap-1 min-w-0 w-full items-start text-left">
                <div class="flex items-center gap-2 mb-1 justify-start">
                    <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">ISTA AI</span>
                    <span x-show="streamingTimeLabel" class="text-[10px] text-gray-400 dark:text-[#64748B]" x-text="streamingTimeLabel"></span>
                </div>
                <div
                    x-data="chatAnswerActions({
                        messageId: () => streamedAssistantMessageId,
                        html: () => streamingHtml,
                        exportUrl: @js(route('documents.export')),
                        exportFileName: () => streamedAssistantMessageId ? `ista-ai-jawaban-${streamedAssistantMessageId}` : 'ista-ai-export',
                    })"
                    :data-answer-message-id="streamedAssistantMessageId || null"
                    class="w-full max-w-[656px]"
                >
                    <div class="{{ $assistantAnswerBubbleClass }}"
                         :class="streamingText === '' ? 'inline-flex items-center w-auto' : ''"
                         x-ref="streamingAnswerBubble"
                         role="status"
                         aria-live="polite">
                        <div x-show="streamingText === ''" class="inline-flex items-center gap-2.5 py-1">
                            <span class="relative inline-flex h-4 w-4 items-center justify-center">
                                <span class="absolute inset-0 animate-spin" style="animation-duration: 2.8s; animation-timing-function: linear;">
                                    <span class="absolute left-1/2 top-0 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-gray-400/90 dark:bg-[#64748B]"></span>
                                    <span class="absolute left-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-400/75 dark:bg-[#64748B]/90"></span>
                                    <span class="absolute right-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-400/60 dark:bg-[#64748B]/80"></span>
                                </span>
                                <span class="absolute left-1/2 top-0 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-gray-500/90 dark:bg-[#94A3B8] animate-pulse" style="animation-duration: 1.3s;"></span>
                                <span class="absolute left-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-500/80 dark:bg-[#94A3B8]/90 animate-pulse" style="animation-duration: 1.5s; animation-delay: 0.12s;"></span>
                                <span class="absolute right-[12%] top-[62%] h-1.5 w-1.5 rounded-full bg-gray-500/70 dark:bg-[#94A3B8]/80 animate-pulse" style="animation-duration: 1.7s; animation-delay: 0.24s;"></span>
                            </span>
                            <span class="ista-loading-shimmer ista-label-enter text-[12px] font-medium whitespace-nowrap"
                                  x-text="loadingPhase"
                                  x-effect="
                                      loadingPhaseKey;
                                      $el.classList.remove('ista-label-enter');
                                      void $el.offsetWidth;
                                      $el.classList.add('ista-label-enter');
                                  "
                            ></span>
                        </div>
                        <div x-show="streamingText !== ''" class="break-words [overflow-wrap:anywhere]" x-html="streamingHtml"></div>
                    </div>
                    <p x-show="stalePendingWarning" x-transition.opacity class="mt-2 max-w-[656px] rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[12px] font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="status" aria-live="polite" x-text="stalePendingWarning"></p>

                    <div
                        x-show="streamingActionsReady && streamedAssistantMessageId && streamingText !== ''"
                        x-transition.opacity
                        style="display: none;"
                    >
                        @include('livewire.chat.partials.assistant-answer-actions')
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button
        type="button"
        x-show="showJumpToLatest"
        x-transition.opacity
        @click="jumpToLatest()"
        title="Ke pesan terbaru"
        aria-label="Ke pesan terbaru"
        class="sticky bottom-3 left-1/2 z-20 inline-flex h-9 w-9 -translate-x-1/2 items-center justify-center rounded-full border border-stone-200/80 bg-white/95 text-stone-600 shadow-lg backdrop-blur-sm transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-200 dark:hover:text-amber-200"
        style="display: none;"
    >
        <span aria-hidden="true" class="text-base leading-none">↓</span>
    </button>
</div>
