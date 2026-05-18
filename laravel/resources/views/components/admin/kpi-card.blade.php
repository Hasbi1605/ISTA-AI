@props([
    'label',
    'value',
    'description' => null,
    'icon' => null,
    'tone' => 'default',
    'trend' => null,
    'trendDirection' => 'neutral',
])

@php
    $toneClass = match ($tone) {
        'primary' => 'admin-kpi--primary',
        'gold' => 'admin-kpi--gold',
        'success' => 'admin-kpi--success',
        'warning' => 'admin-kpi--warning',
        'danger' => 'admin-kpi--danger',
        default => 'admin-kpi--default',
    };

    $trendClass = match ($trendDirection) {
        'up' => 'text-emerald-600 dark:text-emerald-400',
        'down' => 'text-rose-600 dark:text-rose-400',
        default => 'text-stone-500 dark:text-gray-400',
    };
@endphp

<div {{ $attributes->merge(['class' => "admin-kpi {$toneClass}"]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-stone-500 dark:text-gray-400">{{ $label }}</p>
        @if ($icon)
            <span class="admin-kpi__icon" aria-hidden="true">{!! $icon !!}</span>
        @endif
    </div>

    <div class="mt-3 flex items-baseline gap-2">
        <span class="text-3xl font-bold tracking-tight text-stone-900 dark:text-gray-100">{{ $value }}</span>
        @if ($trend)
            <span class="text-xs font-semibold {{ $trendClass }}">{{ $trend }}</span>
        @endif
    </div>

    @if ($description)
        <p class="mt-2 text-xs leading-relaxed text-stone-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @isset($footer)
        <div class="mt-4 border-t border-stone-100 pt-3 text-[11px] font-medium text-stone-500 dark:border-gray-800 dark:text-gray-400">
            {{ $footer }}
        </div>
    @endisset
</div>
