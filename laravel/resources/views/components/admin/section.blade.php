@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->merge(['class' => 'admin-section']) }}>
    @if ($title || $description || isset($actions))
        <header class="admin-section__header">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-base font-semibold text-stone-900 dark:text-gray-100">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-1 text-xs leading-relaxed text-stone-500 dark:text-gray-400">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </header>
    @endif

    <div class="admin-section__body">
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="admin-section__footer">
            {{ $footer }}
        </footer>
    @endisset
</section>
