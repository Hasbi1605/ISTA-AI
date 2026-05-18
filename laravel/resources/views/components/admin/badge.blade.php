@props([
    'tone' => 'neutral',
])

@php
    $toneClass = match ($tone) {
        'success' => 'admin-badge--success',
        'warning' => 'admin-badge--warning',
        'danger' => 'admin-badge--danger',
        'info' => 'admin-badge--info',
        'gold' => 'admin-badge--gold',
        'primary' => 'admin-badge--primary',
        default => 'admin-badge--neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => "admin-badge {$toneClass}"]) }}>
    {{ $slot }}
</span>
