@if($shouldType)
    <div
        wire:ignore
        wire:key="msg-typing-{{ $messageId }}"
        class="{{ $assistantBubbleClass }}"
        x-data="assistantTypewriter({
            content: @js((string) $content),
            initialDelay: 80,
            emptyInitialDelay: 0,
        })"
    >
        @if($isAssistantError)
            <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-rose-700 dark:text-rose-200">
                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-rose-100 text-[10px] dark:bg-rose-500/20">!</span>
                Gagal memproses jawaban
            </div>
        @endif
        <div x-html="typewriterHtml"></div>
    </div>
@else
    <div
        wire:key="msg-static-{{ $messageId }}"
        class="{{ $assistantBubbleClass }}"
    >
        @if($isAssistantError)
            <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-rose-700 dark:text-rose-200">
                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-rose-100 text-[10px] dark:bg-rose-500/20">!</span>
                Gagal memproses jawaban
            </div>
        @endif
        <div x-html="@js((string) $assistantHtml)"></div>
    </div>
@endif
