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
            :disabled="driveLoading || !driveUploadAvailable || !resolvedMessageId()"
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
