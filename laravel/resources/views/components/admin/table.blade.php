@props([
    'columns' => [],
    'rows' => [],
    'emptyMessage' => 'Belum ada data.',
])

<div {{ $attributes->merge(['class' => 'admin-table-wrapper']) }}>
    <table class="admin-table" role="table">
        @if (! empty($columns))
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        @php
                            $align = $column['align'] ?? 'left';
                            $width = $column['width'] ?? null;
                        @endphp
                        <th scope="col"
                            class="admin-table__th"
                            style="{{ $width ? "width: {$width};" : '' }}"
                            data-align="{{ $align }}">
                            {{ $column['label'] ?? '' }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            @if (! empty($rows))
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            @php
                                $key = $column['key'] ?? null;
                                $align = $column['align'] ?? 'left';
                            @endphp
                            <td class="admin-table__td" data-align="{{ $align }}">
                                {{ $key ? ($row[$key] ?? '') : '' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @else
                {{ $slot }}
            @endif

            @if (empty($rows) && trim($slot) === '')
                <tr>
                    <td colspan="{{ max(count($columns), 1) }}" class="admin-table__empty">
                        {{ $emptyMessage }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
