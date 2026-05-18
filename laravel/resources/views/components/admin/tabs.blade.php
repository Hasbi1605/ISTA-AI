@props([
    'tabs' => [],
    'current' => null,
])

<nav {{ $attributes->merge(['class' => 'admin-tabs']) }} aria-label="Tab navigasi">
    <ul class="admin-tabs__list" role="tablist">
        @foreach ($tabs as $tab)
            @php
                $isActive = ($current ?? null) === ($tab['key'] ?? null);
                $href = $tab['route'] ?? '#';
                $label = $tab['label'] ?? '';
                $count = $tab['count'] ?? null;
            @endphp
            <li role="presentation">
                <a href="{{ $href }}"
                   role="tab"
                   aria-selected="{{ $isActive ? 'true' : 'false' }}"
                   @class([
                       'admin-tabs__tab',
                       'admin-tabs__tab--active' => $isActive,
                   ])>
                    <span>{{ $label }}</span>
                    @if (! is_null($count))
                        <span class="admin-tabs__count">{{ $count }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
