@props([
    'title' => 'Belum ada data',
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'admin-empty-state']) }}>
    <div class="admin-empty-state__icon" aria-hidden="true">
        @if ($icon)
            {!! $icon !!}
        @else
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9 17V7a4 4 0 014-4h4l4 4v10a4 4 0 01-4 4H9zM4 7v14a2 2 0 002 2h2"/>
            </svg>
        @endif
    </div>
    <p class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-xs leading-relaxed text-stone-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
