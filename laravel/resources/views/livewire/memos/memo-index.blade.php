<div class="min-h-screen w-full bg-stone-50 text-stone-900 dark:bg-gray-950 dark:text-gray-100">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-6">
        <header class="flex flex-col gap-3 border-b border-stone-200 pb-5 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('chat') }}" wire:navigate class="text-sm font-semibold text-stone-500 hover:text-ista-primary dark:text-gray-400">ISTA AI</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-normal">Memo</h1>
            </div>
            <a href="{{ route('memos.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-ista-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-stone-800">
                Buat memo
            </a>
        </header>

        @if ($memos->isEmpty())
            <section class="flex min-h-[360px] flex-col items-center justify-center rounded-lg border border-dashed border-stone-300 bg-white px-6 text-center dark:border-gray-800 dark:bg-gray-900">
                <h2 class="text-lg font-semibold">Belum ada memo</h2>
                <p class="mt-2 max-w-md text-sm text-stone-600 dark:text-gray-400">Buat draft memo dari instruksi singkat, lalu lanjutkan penyuntingan di canvas dokumen.</p>
            </section>
        @else
            <section class="grid gap-3">
                @foreach ($memos as $memo)
                    <article class="flex flex-col gap-3 rounded-lg border border-stone-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold">{{ $memo->title }}</h2>
                                <span class="rounded-md bg-stone-100 px-2 py-1 text-xs font-medium text-stone-600 dark:bg-gray-800 dark:text-gray-300">{{ $memo->type_label }}</span>
                                <span class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $memo->status }}</span>
                            </div>
                            <p class="mt-1 text-sm text-stone-500 dark:text-gray-400">{{ $memo->updated_at?->format('d M Y H:i') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('memos.download', $memo) }}" class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">DOCX</a>
                            <a href="{{ route('memos.export.pdf', $memo) }}" class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">PDF</a>
                            <a href="{{ route('memos.edit', $memo) }}" wire:navigate class="rounded-lg bg-ista-primary px-3 py-2 text-sm font-semibold text-white hover:bg-stone-800">Buka</a>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</div>
