@props([
    'label' => 'Memuat data',
    'rows' => 3,
])

<div {{ $attributes->merge(['class' => 'admin-loading']) }} role="status" aria-live="polite">
    <span class="sr-only">{{ $label }}</span>
    @for ($i = 0; $i < (int) $rows; $i++)
        <div class="admin-loading__bar" aria-hidden="true">
            <span></span>
        </div>
    @endfor
</div>
