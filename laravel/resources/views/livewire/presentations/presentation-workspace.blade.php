<div class="flex w-full h-full flex-col overflow-hidden bg-transparent">
    {{-- Header konsisten dengan shell Chat/Memo --}}
    <div class="h-[61px] flex-shrink-0 flex items-center justify-between px-3 sm:px-6 z-20 border-b border-stone-200/70 dark:border-[#1E293B]/70 backdrop-blur-sm">
        <div class="flex items-center gap-2 sm:gap-4">
            <div class="ista-brand-title text-xl text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></div>
            <span class="text-[13px] font-semibold text-stone-500 dark:text-gray-400">Presentasi</span>
        </div>
    </div>

    {{-- Empty state: workspace penuh dikerjakan di child #223 --}}
    <div class="flex flex-1 items-center justify-center px-6 py-10 overflow-y-auto">
        <div class="max-w-md text-center">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-ista-primary/10 text-ista-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 5h16M5 5v10a1 1 0 001 1h12a1 1 0 001-1V5M9 21l3-3 3 3M12 9v3" />
                </svg>
            </div>
            <h2 class="text-lg font-bold text-stone-800 dark:text-gray-100">Presentasi ISTA AI</h2>
            <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                Fitur pembuatan materi presentasi resmi sedang disiapkan. Di sini Anda akan dapat
                membuat PPT dari dokumen atau instruksi, memilih template visual, lalu mengunduhnya
                dalam format PPTX dan PDF.
            </p>
            <p class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-stone-100 px-3 py-1 text-[12px] font-semibold text-stone-500 dark:bg-gray-800 dark:text-gray-400">
                <span class="h-1.5 w-1.5 rounded-full bg-ista-gold"></span>
                Segera hadir
            </p>
        </div>
    </div>
</div>
