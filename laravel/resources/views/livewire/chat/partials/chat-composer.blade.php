<div x-data="chatComposer({ prompt: @js($prompt ?? '') })" 
     x-on:show-drop-error.window="sendError = $event.detail.message"
     class="px-3 sm:px-6 pb-4 sm:pb-6 pt-2 bg-transparent w-full"
>
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
            <!-- Error Notification -->
            <div x-show="sendError" x-transition class="absolute -top-14 left-0 right-0 z-30">
                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800 shadow-sm flex items-center justify-between gap-2">
                    <span x-text="sendError"></span>
                    <button type="button" @click="sendError = ''" class="text-rose-400 hover:text-rose-600">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

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
                            <button type="button" wire:click="removeConversationDocument({{ $doc->id }})" class="text-[#7C8DA8] hover:text-[#314158] dark:text-gray-300 dark:hover:text-white" title="Lepas dokumen">
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
                    placeholder="Tulis pertanyaan atau arahan kerja Anda..."
                    class="chat-input w-full max-h-[200px] min-h-[44px] bg-transparent border-none focus:ring-0 focus:outline-none focus:border-transparent focus-visible:ring-0 focus-visible:outline-none resize-none text-[14.5px] text-stone-800 dark:text-[#F8FAFC] placeholder-[#94A3B8] dark:placeholder-[#64748B] px-2 py-[10px] hover:bg-transparent focus:bg-transparent"
                    rows="1"
                    style="outline: none !important; box-shadow: none !important;"
                ></textarea>

                <div class="flex items-center justify-between mt-2">
                    <button type="button" wire:click="toggleWebSearch" class="h-[30px] px-[13px] rounded-full text-[11.4px] font-normal flex items-center gap-[6px] transition-all duration-300 {{ $webSearchMode ? 'bg-blue-50 dark:bg-blue-500/10 border border-blue-400 dark:border-blue-500 text-blue-600 dark:text-blue-400' : 'bg-transparent border border-stone-200 dark:border-[#334155] text-[#62748E] dark:text-[#94A3B8]' }}">
                        <svg class="w-[14px] h-[14px] text-current" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 12.8333C10.2217 12.8333 12.8333 10.2217 12.8333 7C12.8333 3.77834 10.2217 1.16667 7 1.16667C3.77834 1.16667 1.16667 3.77834 1.16667 7C1.16667 10.2217 3.77834 12.8333 7 12.8333Z" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M7 1.16667C5.50214 2.73942 4.66667 4.8281 4.66667 7C4.66667 9.1719 5.50214 11.2606 7 12.8333C8.49786 11.2606 9.33333 9.1719 9.33333 7C9.33333 4.8281 8.49786 2.73942 7 1.16667Z" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M1.16667 7H12.8333" stroke="currentColor" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Web</span>
                    </button>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="openAttachmentPicker()" wire:loading.attr="disabled" wire:target="chatAttachment" class="h-[34px] w-[34px] rounded-full transition-colors flex items-center justify-center bg-transparent hover:bg-[#F1F5F9] dark:hover:bg-gray-800 disabled:opacity-60" title="Lampirkan file">
                            <img src="{{ $uiIcons['uploadLight'] }}" alt="" class="h-[18px] w-[18px] dark:hidden" />
                            <img src="{{ $uiIcons['uploadDark'] }}" alt="" class="h-[18px] w-[18px] hidden dark:block" />
                        </button>

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
                    Seret file ke sini untuk mengunggah
                </div>
            </div>
        </div>
    </form>
    <p x-show="dropError" x-transition.opacity class="max-w-3xl mx-auto mt-2 text-xs text-red-500 dark:text-red-400" x-text="dropError"></p>
    <div class="text-center mt-3 text-[11px] text-[#94A3B8] dark:text-[#64748B]">
        ISTA AI dapat keliru. Mohon verifikasi kembali informasi yang penting.
    </div>
</div>
