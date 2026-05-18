@props([
    'name',
    'options' => [],
    'label' => null,
    'placeholder' => 'Semua',
])

<label class="admin-filter">
    @if ($label)
        <span class="admin-filter__label">{{ $label }}</span>
    @endif
    <select name="{{ $name }}" {{ $attributes->merge(['class' => 'admin-filter__control']) }}>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $key => $value)
            <option value="{{ $key }}">{{ $value }}</option>
        @endforeach
    </select>
</label>
