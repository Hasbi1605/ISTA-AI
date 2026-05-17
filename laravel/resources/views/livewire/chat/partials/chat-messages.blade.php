<div x-data="chatMessages"
     class="mx-auto min-h-0 w-full max-w-4xl flex-1 overflow-y-auto px-3 py-5 sm:px-6 sm:py-7 space-y-6 sm:space-y-8"
     x-ref="chatBox"
     data-chat-box
     x-on:message-streamed.window="scrollToBottom()"
     x-on:message-send.window="optimisticUserMessage = $event.detail.text; isSwitchingConversation = false; startStreamingPlaceholder($event.detail.loadingContext || 'general'); scrollToBottom(true)"
     x-on:conversation-loading.window="isSwitchingConversation = true; optimisticUserMessage = ''; resetStreamingState()"
     x-on:conversation-loaded.window="isSwitchingConversation = false; resetStreamingState(); $nextTick(() => { maybeRestorePendingPlaceholder(); scrollToBottom(); })"
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
            <p class="text-gray-500 dark:text-[#94A3B8] text-[14px]">
                Mulai percakapan baru untuk meminta ringkasan, informasi, atau bantuan kerja.
            </p>
        </div>
    @else

    @foreach($messages as $message)
        @php
            $isUserMessage = $message['role'] == 'user';
            $isAssistantError = ! $isUserMessage && (bool) ($message['is_error'] ?? false);
        @endphp
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
                            $assistantHtml = str($message['content'])->markdown([
                                'html_input' => 'strip',
                                'allow_unsafe_links' => false,
                            ]);
                            $exportFileName = 'ista-ai-jawaban-' . $message['id'];
                            $assistantBubbleClass = $isAssistantError
                                ? 'rounded-2xl rounded-bl-sm bg-rose-50/95 backdrop-blur-sm dark:bg-rose-950/30 border border-rose-200/80 dark:border-rose-500/30 px-4 py-3 text-[14.5px] leading-relaxed text-rose-900 dark:text-rose-100 prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-rose-700 prose-a:text-rose-700 prose-a:decoration-rose-500/80 hover:prose-a:text-rose-900 dark:prose-headings:text-rose-100 dark:prose-p:text-rose-100 dark:prose-strong:text-rose-50 dark:prose-ul:text-rose-100 dark:prose-ol:text-rose-100 dark:prose-li:text-rose-100 dark:prose-li:marker:text-rose-100 dark:prose-a:text-rose-200 dark:prose-a:decoration-rose-200/90 dark:hover:prose-a:text-white pb-1'
                                : 'rounded-2xl rounded-bl-sm bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 px-4 py-3 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-stone-800 prose-a:text-sky-700 prose-a:decoration-sky-600/80 hover:prose-a:text-sky-800 dark:prose-headings:text-white dark:prose-p:text-gray-100 dark:prose-strong:text-white dark:prose-ul:text-gray-100 dark:prose-ol:text-gray-100 dark:prose-li:text-gray-100 dark:prose-li:marker:text-white dark:prose-a:text-sky-300 dark:prose-a:decoration-sky-300/90 dark:hover:prose-a:text-sky-200 pb-1';
                        @endphp
                        <div
                            wire:key="chat-answer-actions-{{ $message['id'] }}"
                            data-answer-message-id="{{ $message['id'] }}"
                            x-data="chatAnswerActions({
                                messageId: @js((int) $message['id']),
                                html: @js((string) $assistantHtml),
                                exportUrl: @js(route('documents.export')),
                                exportFileName: @js($exportFileName),
                                driveUploadAvailable: @js($googleDriveUploadAvailable ?? false),
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

                            <div data-answer-actions class="mt-2 flex flex-wrap items-center gap-1 text-[12px] text-[#64748B] dark:text-[#94A3B8]">
                                <button
                                    type="button"
                                    @click="copyToClipboard()"
                                    :title="copyStatusLabel()"
                                    :aria-label="copyStatusLabel()"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-white/80 hover:text-stone-900 dark:hover:bg-gray-800/80 dark:hover:text-gray-100"
                                    >
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                    </svg>
                                    <span class="sr-only" x-text="copyStatusLabel()">Salin</span>
                                </button>
                                <span
                                    x-show="copied"
                                    x-transition.opacity.duration.150ms
                                    class="inline-flex h-7 items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 text-[11px] font-medium text-emerald-700 shadow-sm dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                    style="display: none;"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="m5 12 4 4L19 6" />
                                    </svg>
                                    Tersalin
                                </span>

                                <button
                                    type="button"
                                    @click="shareToWhatsApp()"
                                    title="Bagikan ke WhatsApp"
                                    aria-label="Bagikan ke WhatsApp"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-white/80 hover:text-stone-900 dark:hover:bg-gray-800/80 dark:hover:text-gray-100"
                                >
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
                                    </svg>
                                    <span class="sr-only">Bagikan ke WhatsApp</span>
                                </button>

                                <div class="relative" x-on:click.outside="driveMenuOpen = false">
                                    <button
                                        type="button"
                                        @click="toggleDriveMenu()"
                                        :disabled="driveLoading || !driveUploadAvailable"
                                        :title="driveButtonLabel()"
                                        :aria-label="driveButtonLabel()"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-white/80 hover:text-stone-900 disabled:cursor-not-allowed dark:hover:bg-gray-800/80 dark:hover:text-gray-100"
                                    >
                                        <span x-show="driveLoading" class="h-[18px] w-[18px] rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>
                                        <span
                                            x-show="!driveLoading"
                                            class="h-[18px] w-[18px] bg-current"
                                            style="-webkit-mask: url('{{ $uiIcons['googleDrive'] }}') center / contain no-repeat; mask: url('{{ $uiIcons['googleDrive'] }}') center / contain no-repeat;"
                                            aria-hidden="true"
                                        ></span>
                                        <span class="sr-only">Upload ke Google Drive</span>
                                    </button>

                                    <div
                                        x-show="driveMenuOpen"
                                        x-transition.opacity
                                        class="absolute left-0 z-20 mt-2 w-52 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
                                        style="display: none;"
                                    >
                                        <div class="border-b border-stone-200 bg-stone-50 px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-stone-500 dark:border-gray-700 dark:bg-gray-800/80 dark:text-gray-400">
                                            Upload ke Drive
                                        </div>
                                        <button type="button" @click="uploadToGoogleDrive('pdf')" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80">
                                            <span>PDF</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Laporan</span>
                                        </button>
                                        <button type="button" @click="uploadToGoogleDrive('docx')" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80">
                                            <span>DOCX</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Word</span>
                                        </button>
                                        <button type="button" @click="uploadToGoogleDrive('xlsx')" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80">
                                            <span>XLSX</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Sheet</span>
                                        </button>
                                        <button type="button" @click="uploadToGoogleDrive('csv')" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80">
                                            <span>CSV</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Tabel</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="relative" x-on:click.outside="exportMenuOpen = false">
                                    <button
                                        type="button"
                                        @click="toggleExportMenu()"
                                        :disabled="exportLoading"
                                        :title="exportLoading ? 'Menyiapkan ekspor' : 'Ekspor'"
                                        :aria-label="exportLoading ? 'Menyiapkan ekspor' : 'Ekspor'"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-white/80 hover:text-stone-900 disabled:cursor-wait disabled:opacity-70 dark:hover:bg-gray-800/80 dark:hover:text-gray-100"
                                        >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M12 15V3" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="m7 10 5 5 5-5" />
                                        </svg>
                                        <span class="sr-only" x-text="exportLoading ? 'Menyiapkan ekspor' : 'Ekspor'">Ekspor</span>
                                    </button>

                                    <div
                                        x-show="exportMenuOpen"
                                        x-transition.opacity
                                        class="absolute left-0 z-20 mt-2 w-44 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
                                        style="display: none;"
                                    >
                                        <button
                                            type="button"
                                            @click="exportAs('pdf')"
                                            class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80"
                                        >
                                            <span>PDF</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Laporan</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="exportAs('docx')"
                                            class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80"
                                        >
                                            <span>DOCX</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Word</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="exportAs('xlsx')"
                                            class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80"
                                        >
                                            <span>XLSX</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Sheet</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="exportAs('csv')"
                                            class="flex w-full items-center justify-between px-4 py-2.5 text-left text-[12px] text-stone-700 transition hover:bg-stone-50 dark:text-gray-100 dark:hover:bg-gray-700/80"
                                        >
                                            <span>CSV</span>
                                            <span class="text-[10px] text-[#64748B] dark:text-[#94A3B8]">Tabel</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <p x-show="exportError" x-transition.opacity class="mt-1 text-[11px] text-rose-500" x-text="exportError"></p>
                            <p x-show="driveError" x-transition.opacity class="mt-1 text-[11px] text-rose-500" x-text="driveError"></p>
                            <div x-show="driveResult" x-transition.opacity class="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100" style="display: none;">
                                <span class="font-medium">Tersimpan ke Google Drive</span>
                                <a x-show="driveResult?.web_view_link"
                                   :href="driveResult?.web_view_link"
                                   target="_blank"
                                   rel="noreferrer"
                                   class="font-semibold underline decoration-emerald-500/40 underline-offset-2 hover:text-emerald-900 dark:hover:text-white">
                                    Buka di Drive
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="inline-block w-fit max-w-[656px] min-w-0 rounded-2xl rounded-br-sm bg-ista-primary px-4 py-3 text-[14.5px] leading-relaxed text-white shadow-sm">
                            <p class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $message['content'] }}</p>
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
         x-show="streaming">
        <div class="w-full sm:max-w-3xl flex flex-row items-start gap-4 px-2 sm:px-8">
             <div class="shrink-0 h-8 w-8 rounded-full bg-white border border-stone-200 shadow-sm p-1 flex items-center justify-center">
                 <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-full w-full object-contain" />
             </div>
             <div class="flex flex-col gap-1 items-start w-full">
                 <div class="flex items-center gap-2 mb-1">
                     <span class="text-[13px] font-bold text-stone-800 dark:text-[#F8FAFC]">ISTA AI</span>
                  </div>
                   <div class="rounded-2xl rounded-bl-sm bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-gray-800 px-4 py-3 text-[14.5px] leading-relaxed text-stone-700 dark:text-gray-100 prose prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-li:my-0 prose-li:marker:text-stone-800 prose-a:text-sky-700 prose-a:decoration-sky-600/80 hover:prose-a:text-sky-800 dark:prose-headings:text-white dark:prose-p:text-gray-100 dark:prose-strong:text-white dark:prose-ul:text-gray-100 dark:prose-ol:text-gray-100 dark:prose-li:text-gray-100 dark:prose-li:marker:text-white dark:prose-a:text-sky-300 dark:prose-a:decoration-sky-300/90 dark:hover:prose-a:text-sky-200 pb-1"
                       :class="streamingText === '' ? 'inline-flex items-center w-auto' : 'w-full max-w-[656px]'"
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

                  <template x-if="sourcesVisible && sources && Array.isArray(sources) && sources.length > 0">
                     <div class="mt-2.5 w-full text-left"
                          x-transition:enter="transition ease-out duration-300 transform"
                          x-transition:enter-start="opacity-0 translate-y-2"
                          x-transition:enter-end="opacity-100 translate-y-0">
                         <p class="text-[10px] font-bold text-[#64748B] dark:text-[#94A3B8] uppercase tracking-wider mb-2 flex items-center gap-1.5 pl-0.5">
                             <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                             </svg>
                             Rujukan:
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
                                             <span class="truncate block max-w-[200px]" x-text="source.filename || 'Dokumen rujukan'"></span>
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
